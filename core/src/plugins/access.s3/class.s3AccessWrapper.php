<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 *
 */
defined('AJXP_EXEC') or die( 'Access not allowed');

require_once(AJXP_INSTALL_PATH."/plugins/access.fs/class.fsAccessWrapper.php");

/**
 * Encapsulation of the PEAR webDAV client
 * @package AjaXplorer_Plugins
 * @subpackage Access
 */
class s3AccessWrapper extends fsAccessWrapper
{
    public static $lastException;
    protected static $clients = [];

    /**
     * @param Repository $repoObject
     * @param boolean $registerStream
     * @return \AccessS3\S3Client
     */
    protected static function getClientForRepository($repoObject, $registerStream = true)
    {
        require_once("aws.phar");
        if (!isSet(self::$clients[$repoObject->getId()])) {
            // Get a client
            $options = array(
                'key' => $repoObject->getOption("API_KEY"),
                'secret' => $repoObject->getOption("SECRET_KEY")
            );
            $signatureVersion = $repoObject->getOption("SIGNATURE_VERSION");
            if (!empty($signatureVersion)) {
                $options['signature'] = $signatureVersion;
            }
            $baseURL = $repoObject->getOption("STORAGE_URL");
            if (!empty($baseURL)) {
                $options["base_url"] = $baseURL;
            }
            $region = $repoObject->getOption("REGION");
            if (!empty($region)) {
                $options["region"] = $region;
            }
            $proxy = $repoObject->getOption("PROXY");
            if (!empty($proxy)) {
                $options['request.options'] = array('proxy' => $proxy);
            }
            $apiVersion = $repoObject->getOption("API_VERSION");
            if ($apiVersion === "") {
                $apiVersion = "latest";
            }
            //SDK_VERSION IS A GLOBAL PARAM
            ConfService::getConfStorageImpl()->_loadPluginConfig("access.s3", $globalOptions);
            $sdkVersion = $globalOptions["SDK_VERSION"]; //$repoObject->driverInstance->driverConf['SDK_VERSION'];
            if ($sdkVersion !== "v2" && $sdkVersion !== "v3") {
                $sdkVersion = "v2";
            }
            if ($sdkVersion === "v3") {
                require_once(__DIR__ . DIRECTORY_SEPARATOR . "class.pydioS3Client.php");
                $s3Client = new \AccessS3\S3Client([
                    "version" => $apiVersion,
                    "region" => $region,
                    "credentials" => $options
                ]);
                $s3Client->registerStreamWrapper($repoObject->getId());
            } else {
                $s3Client = Aws\S3\S3Client::factory($options);
                if ($repoObject->getOption("VHOST_NOT_SUPPORTED")) {
                    // Use virtual hosted buckets when possible
                    require_once("ForcePathStyleListener.php");
                    $s3Client->addSubscriber(new \Aws\S3\ForcePathStyleStyleListener());
                }
                $s3Client->registerStreamWrapper();
            }
            self::$clients[$repoObject->getId()] = $s3Client;
        }
        return self::$clients[$repoObject->getId()];
    }

    /**
     * Initialize the stream from the given path.
     * Concretely, transform ajxp.s3:// into s3://
     *
     * @param string $path
     * @param $streamType
     * @param bool $storeOpenContext
     * @param bool $skipZip
     * @return mixed Real path or -1 if currentListing contains the listing : original path converted to real path
     * @throws Exception
     */
    protected static function initPath($path, $streamType, $storeOpenContext = false, $skipZip = false)
    {
        $url = parse_url($path);
        $repoId = $url["host"];
        $repoObject = ConfService::getRepositoryById($repoId);
        if (!isSet($repoObject)) {
            $e = new Exception("Cannot find repository with id ".$repoId);
            self::$lastException = $e;
            throw $e;
        }
        // Make sure to register s3:// wrapper
        $client = self::getClientForRepository($repoObject, true);
        $protocol = "s3://";
        if ($client instanceof \AccessS3\S3Client) {
            $protocol = "s3." . $repoId . "://";
        }
        $basePath = $repoObject->getOption("PATH");
        $baseContainer = $repoObject->getOption("CONTAINER");
        if(!empty($basePath)){
            $baseContainer.=rtrim($basePath, "/");
        }
        $p = $protocol . $baseContainer . str_replace("//", "/", $url["path"]);
        return $p;
    }

    /**
     * Opens the stream
     * Diff with parent class : do not "securePath", as it removes double slash
     *
     * @param String $path Maybe in the form "ajxp.fs://repositoryId/pathToFile"
     * @param String $mode
     * @param string $options
     * @param resource $context
     * @return resource
     * @internal param string $opened_path
     */
    public function stream_open($path, $mode, $options, &$context)
    {
        try {
            $this->realPath = $this->initPath($path, "file");
        } catch (Exception $e) {
            AJXP_Logger::error(__CLASS__,"stream_open", "Error while opening stream $path");
            return false;
        }
        if ($this->realPath == -1) {
            $this->fp = -1;
            return true;
        } else {
            $this->fp = fopen($this->realPath, $mode, $options);
            return ($this->fp !== false);
        }
    }

    /**
     * Stats the given path.
     * Fix PEAR by adding S_ISREG mask when file case.
     *
     * @param string $path
     * @param integer $flags
     * @return array
     */
    public function url_stat($path, $flags)
    {
        // File and zip case
        // AJXP_Logger::debug(__CLASS__,__FUNCTION__,"Stating $path");
        $stat = @stat($this->initPath($path, "file"));
        if($stat == null) return null;
        if ($stat["mode"] == 0666) {
            $stat[2] = $stat["mode"] |= 0100000; // S_ISREG
        }

        $parsed = parse_url($path);
        if ($stat["mtime"] == $stat["ctime"]  && $stat["ctime"] == $stat["atime"] && $stat["atime"] == 0 && $parsed["path"] != "/") {
            //AJXP_Logger::debug(__CLASS__,__FUNCTION__,"Nullifying stats");
            //return null;
        }
        return $stat;
    }

    /**
     * Opens a handle to the dir
     * Fix PEAR by being sure it ends up with "/", to avoid
     * adding the current dir to the children list.
     *
     * @param string $path
     * @param string $options
     * @return resource
     */
    public function dir_opendir ($path , $options )
    {
        $this->realPath = $this->initPath($path, "dir", true);
        if ($this->realPath[strlen($this->realPath)-1] != "/") {
            $this->realPath.="/";
        }
        if (is_string($this->realPath)) {
            $this->dH = @opendir($this->realPath);
        } else if ($this->realPath == -1) {
            $this->dH = -1;
        }
        return $this->dH !== false;
    }


    // DUPBLICATE STATIC FUNCTIONS TO BE SURE
    // NOT TO MESS WITH self:: CALLS

    public static function removeTmpFile($tmpDir, $tmpFile)
    {
        if(is_file($tmpFile)) unlink($tmpFile);
        if(is_dir($tmpDir)) rmdir($tmpDir);
    }

    /**
     * @inheritdoc
     */
    public static function getRealFSReference($path, $persistent = false)
    {
        $tmpFile = AJXP_Utils::getAjxpTmpDir()."/".md5(time()).".".pathinfo($path, PATHINFO_EXTENSION);
           $tmpHandle = fopen($tmpFile, "wb");
           self::copyFileInStream($path, $tmpHandle);
           fclose($tmpHandle);
           if (!$persistent) {
               register_shutdown_function(array("AJXP_Utils", "silentUnlink"), $tmpFile);
           }
           return $tmpFile;
    }

    /**
     * @inheritdoc
     */
    public static function isRemote()
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function copyFileInStream($path, $stream)
    {
        AJXP_Logger::debug(__CLASS__,__FUNCTION__,"Should load ".$path);
        $fp = fopen($path, "r");
        if(!is_resource($fp)) return;
        while (!feof($fp)) {
            $data = fread($fp, 4096);
            fwrite($stream, $data, strlen($data));
        }
        fclose($fp);
    }

    /**
     * @inheritdoc
     */
    public static function changeMode($path, $chmodValue)
    {
    }

    /**
     * @inheritdoc
     */
    public function rename($from, $to)
    {

        $fromUrl = parse_url($from);
        $repoId = $fromUrl["host"]; 
        $repoObject = ConfService::getRepositoryById($repoId);
        $isViPR = $repoObject->getOption("IS_VIPR");
        $isDir = false;
        if($isViPR === true) {
            if(is_dir($from . "/")) {
                $from .= '/';
                $to .= '/';
                $isDir = true;
            }
        }

        if($isDir === true || is_dir($from)){
            AJXP_Logger::debug(__CLASS__, __FUNCTION__, "S3 Renaming dir $from to $to");
            require_once("aws-v2.phar");

            $fromUrl = parse_url($from);
            $repoId = $fromUrl["host"];
            $repoObject = ConfService::getRepositoryById($repoId);
            if (!isSet($repoObject)) {
                $e = new Exception("Cannot find repository with id ".$repoId);
                self::$lastException = $e;
                throw $e;
            }
            $s3Client = self::getClientForRepository($repoObject, false);
            $bucket = $repoObject->getOption("CONTAINER");
            $basePath = $repoObject->getOption("PATH");
            $fromKeyname   = trim(str_replace("//", "/", $basePath.parse_url($from, PHP_URL_PATH)),'/');
            $toKeyname   = trim(str_replace("//", "/", $basePath.parse_url($to, PHP_URL_PATH)), '/');
            if($isViPR) {
                $toKeyname .= '/';
                $parts = explode('/', $bucket);
                $bucket = $parts[0];
                if(isset($parts[1])) {
                    $fromKeyname = $parts[1] . "/" . $fromKeyname;
                }
            }

            // Perform a batch of CopyObject operations.
            $batch = array();
            $failed = array();
            $iterator = $s3Client->getIterator('ListObjects', array(
                'Bucket'     => $bucket,
                'Prefix'     => $fromKeyname."/"
            ));
            $toDelete = array();
            AJXP_Logger::debug(__CLASS__, __FUNCTION__, "S3 Got iterator looking for prefix ".$fromKeyname."/ , and toKeyName=".$toKeyname);
            foreach ($iterator as $object) {

                $currentFrom = $object['Key'];
                $currentTo = $toKeyname.substr($currentFrom, strlen($fromKeyname));
                if($isViPR) {
                    if(isset($parts[1])) {
                        $currentTo = $parts[1] . "/" . $currentTo;
                    }
                }
                AJXP_Logger::debug(__CLASS__, __FUNCTION__, "S3 Should move one object ".$currentFrom. " to  new key :".$currentTo);
                $batch[] = $s3Client->getCommand('CopyObject', array(
                    'Bucket'     => $bucket,
                    'Key'        => "{$currentTo}",
                    'CopySource' => "{$bucket}/".rawurlencode($currentFrom),
                ));
                $toDelete[] = $currentFrom;
            }
            AJXP_Logger::debug(__CLASS__, __FUNCTION__, "S3 Execute batch on ".count($batch)." objects");
            ConfService::getConfStorageImpl()->_loadPluginConfig("access.s3", $globalOptions);
            $sdkVersion = $globalOptions["SDK_VERSION"];
            if ($sdkVersion === "v3") {
                foreach ($batch as $command) {
                    $successful = $s3Client->execute($command);
                }
                //We must delete the "/" in $fromKeyname because we want to delete the folder
                $clear = \Aws\S3\BatchDelete::fromIterator($s3Client, $bucket, $s3Client->getIterator('ListObjects', array(
                    'Bucket'     => $bucket,
                    'Prefix'     => $fromKeyname
                )));
                $clear->delete();
            } else {
                try {
                    $successful = $s3Client->execute($batch);
                    $clear = new \Aws\S3\Model\ClearBucket($s3Client, $bucket);
                    $iterator->rewind();
                    $clear->setIterator($iterator);
                    $clear->clear();
                    $failed = array();
                } catch (\Guzzle\Service\Exception\CommandTransferException $e) {
                    $successful = $e->getSuccessfulCommands();
                    $failed = $e->getFailedCommands();
                }
            }
            if(count($failed)){
                foreach($failed as $c){
                    // $c is a Aws\S3\Command\S3Command
                    AJXP_Logger::error("S3Wrapper", __FUNCTION__, "Error while copying: ".$c->getOperation()->getServiceDescription());
                }
                self::$lastException = new Exception("Failed moving folder: ".count($failed));
                return false;
            }
            return true;
        }else{
            AJXP_Logger::debug(__CLASS__, __FUNCTION__, "S3 Execute standard rename on ".$from." to ".$to);
            return parent::rename($from, $to);
        }
    }

}
