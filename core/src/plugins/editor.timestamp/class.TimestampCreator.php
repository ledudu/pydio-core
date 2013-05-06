<?php
/*
Timestamp plugin by Ffdecourt (fdecourt@gmail.com) for Ajaxplorer
This plugin allows you to add a certified timestamp by Universign.eu on your documents.
v0.1
*/

defined('AJXP_EXEC') or die('Access not allowed');

class TimestampCreator extends AJXP_Plugin
{
	function switchAction($action, $httpVars, $fileVars){

		$mess = ConfService::getMessages();
                
		//Check if the configuration has been initiated
		if(!isSet($this->pluginConf["TIMESTAMP_URL"]) || !isSet($this->pluginConf["USER"]) || !isSet($this->pluginConf["PASS"]) ){
			throw new AJXP_Exception($mess["timestamp.4"]);
			AJXP_Logger::logAction("error", "TimeStamp : configuration is needed");
			return false;
		}
		
		$timestamp_url = $this->pluginConf["TIMESTAMP_URL"];
		$timestamp_login = $this->pluginConf["USER"];
		$timestamp_password = $this->pluginConf["PASS"];
                
		//Check if after being initiated, conf. fields have some values
		if(strlen($timestamp_url)<2 || strlen($timestamp_login)<2 || strlen($timestamp_password)<2 ){
			throw new AJXP_Exception($mess["timestamp.4"]);
			AJXP_Logger::logAction("error", "TimeStamp : configuration is incorrect");
			return false;
		}
    
		//Get active repository
		$repository = ConfService::getRepository();
		if(!$repository->detectStreamWrapper(true)){
			return false;
		}
		
		//Retreive file info
		$streamData = $repository->streamData;
		$this->streamData = $streamData;
		$destStreamURL = $streamData["protocol"]."://".$repository->getId();
		$fileName = AJXP_Utils::decodeSecureMagic($httpVars["file"]);
		$fileUrl = $destStreamURL.AJXP_Utils::decodeSecureMagic($httpVars["file"]);
		$file = call_user_func(array($this->streamData["classname"], "getRealFSReference"), $fileUrl, true);

		//Hash the file, to send it to Universign
		$hashedDataToTimestamp = hash_file('sha256', $file);

		//Check that a tokken is not going to be timestamped !
		if(substr("$file", -4)!='.ers') {
			if(file_exists($file.'.ers')) {
				throw new AJXP_Exception($mess["timestamp.1"]);
				return false;
			}
			else {
				//Prepare the query that will be sent to Universign
				$dataToSend = array ('hashAlgo' => 'SHA256', 'withCert' => 'true', 'hashValue' => $hashedDataToTimestamp);
				$dataQuery = http_build_query($dataToSend);

				//Check if allow_url_fopen is allowed on the server. If not, it will use cUrl
				if(ini_get('allow_url_fopen')) {
					$context_options = array (
						'http' => array (
							'method' => 'POST',
							'header'=> "Content-type: application/x-www-form-urlencoded\r\n"
							."Content-Length: " . strlen($dataQuery) . "\r\n"
							."Authorization: Basic ".base64_encode($timestamp_login.':'.$timestamp_password)."\r\n",
							'content' => $dataQuery
						)
					);
                                        
					//Get the result from Universign
					$context = stream_context_create($context_options);
					$fp = fopen($timestamp_url, 'r', false, $context);
					$tsp = stream_get_contents($fp);
				}
				//Use Curl if allow_url_fopen is not available
				else
				{

					$timestamp_header = array ("Content-type: application/x-www-form-urlencoded", "Content-Length: " . strlen($dataQuery), "Authorization: Basic ".base64_encode($timestamp_login.':'.$timestamp_password));
					$timeout = 5;
					$ch = curl_init($timestamp_url);
					curl_setopt($ch, CURLOPT_POSTFIELDS, $dataQuery );
					curl_setopt($ch,CURLOPT_HTTPHEADER,$timestamp_header );	
					curl_setopt($ch, CURLOPT_POST, 1); 
					curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);							 
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                        //Get the result from Universign
					$tsp=curl_exec($ch);
					curl_close($ch);
				}
                                
				//Save the result to a file
				file_put_contents( $file.'.ers', $tsp);

				//Send the succesful message
				AJXP_Logger::logAction("TimeStamp", array("files"=>$file, "destination"=>$file.'.ers'));
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::reloadDataNode();
				AJXP_XMLWriter::sendMessage($mess["timestamp.3"].$fileName, null);
				AJXP_XMLWriter::close();
			}

		}
		else{
			throw new AJXP_Exception($mess["timestamp.2"]);
			return false;
		}
	}
}
