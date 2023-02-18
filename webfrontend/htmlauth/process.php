<?php
require_once("loxberry_system.php");
require_once("loxberry_io.php");
require_once("loxberry_log.php");
require_once("loxberry_json.php");
require_once("phpMQTT/phpMQTT.php");
define ("Endpoint", "https://api.tibber.com/v1-beta/gql");
define ("CacheFile", LBPDATADIR."/pricecache.json");

//Start logging
$log = LBLog::newLog(["name" => "Process.php"]);
LOGSTART("Script called.");

//Decide for and run function
$requestedAction = "";
if(isset($_POST["action"])){
	LOGINF("Started from HTTP.");
	$requestedAction = $_POST["action"];
}
if(isset($argv)){
	LOGINF("Started from Cron.");
	$requestedAction = $argv[1];
}

switch ($requestedAction){
	case "forcepricerequest":
		forcepricerequest();
		LOGEND("Processing finished.");
		break;
	case "getconsumptions":
		break;	
	case "getconfigasjson":
		LOGTITLE("getconfigasjson");
		getconfigasjson(true);
		LOGEND("Processing finished.");
		break;
	case "savejsonasconfig":
		LOGTITLE("savejsonasconfig");
		savejsonasconfig($_POST["configToSave"]);
		LOGEND("Processing finished.");
		break;
	case "clearcache":
		clearcache();
		LOGEND("Processing finished.");
		break;
	default:
		http_response_code(404);
		notify(LBPCONFIGDIR, "tibber2mqtt", "process.php has been called without parameter.", "error");
		LOGERR("No action has been requested");
		break;
}

//Function definitions
function getconfigasjson($output = false){
	LOGINF("Switched to getconfigasjson");
	
	//Get Config
	$tb_conf = new LBJSON(LBPCONFIGDIR."/config.json");
	LOGDEB("Retrieved config:".json_encode($tb_conf));
	
	if($output){
		echo json_encode($tb_conf->slave); 
		return;
	}else{
		return $tb_conf;
	}
}

function savejsonasconfig($config){
	LOGINF("Switched to savejsonasconfig");
	
	if(!isset($config) || $config == "" || $config == null || $config == "null"){
		http_response_code(404);
		notify(LBPCONFIGDIR, "tibber2mqtt", "Saveconfig has been called without valid config.", "error");
		LOGERR("Saveconfig has been called without valid config.");
		return;
	}
	
	LOGDEB("Config to save:".$config);
	
	//Get Config
	$tb_conf = getconfigasjson();
	
	// Change a value 
	$tb_conf->slave = json_decode($config);
	
	LOGDEB("Updated config object:".json_encode($tb_conf));
	
	// Write all changes
	$tb_conf->write();
	
	//End in same way as ajax-generic of LB3
	echo json_encode($tb_conf->slave); 
	return;
}

function forcepricerequest(){	
	LOGINF("Switched to forcepricerequest");
	LOGTITLE("forcepricerequest");
	
	//Default PriceSetting String
	$query_prices = "{
		viewer {
			homes {
				currentSubscription {
					priceInfo {
						today {
							%placeholder%
						}
						tomorrow {
							%placeholder%
						}
					}
				}
			}
		}
	}";
	
	//Get Config
	$tb_config = getconfigasjson();
	$tb_config = $tb_config->slave;
	
	if(!isset($tb_config->Main->token) OR $tb_config->Main->token == ""){
		//Abort, as token not available.
		http_response_code(404);
		notify(LBPCONFIGDIR, "tibber2mqtt", "No Tibber token saved in settings.", "error");
		LOGERR("No Tibber token saved in settings");
		return;
	}
	
	//Define Requested Values array and fill based on config | needed also if cached values are used
	$reqAbsoluteValArr = array();
	$reqRelativeValArr = array();
	
	if($tb_config->AbsolutePrice == true){
		foreach($tb_config->AbsolutePrices as $priceSettingKey => $priceSettingVal){
			if($priceSettingVal == true){
				array_push($reqAbsoluteValArr, $priceSettingKey);
			}	
		}
	}
		
	if($tb_config->RelativePrice == true){
		foreach($tb_config->RelativePrices as $priceSettingKey => $priceSettingVal){
			if($priceSettingVal == true){
				array_push($reqRelativeValArr, $priceSettingKey);
			}	
		}
	}
	
	//Try to load local cache and check if it's up to date
	$cachedData = "";
	if (file_exists(CacheFile) && date ("d.m.Y", filemtime(CacheFile)) == date ("d.m.Y") && (date("H") < 13 || (date("H") >= 13 && date("H", filemtime(CacheFile)) >= 13))){
		//Use cache value
		$cachedData = json_decode(file_get_contents(CacheFile), TRUE); 
		if(is_array($cachedData) && $cachedData["tomorrow"][0]["total"] != "") $processedPrices = $cachedData; //Double check first value of tomorrow, to ensure that prices are retrieved again, in case API did not deliver at 13:00 o'clock.
	}
	
	//If value has not been retrieved, go ahead with reading from API
	if(!isset($processedPrices)){
		LOGINF("No cached data, retriev from API");

		$reqValuesAllString = "startsAt";
		$reqValArrAll = array_unique(array_merge($reqAbsoluteValArr, $reqRelativeValArr));

		for($i=0;$i < count($reqValArrAll); $i++){
			$reqValuesAllString .= "\r\n".$reqValArrAll[$i];
		}

		//Build GraphQL String
		$datas = json_encode(['query' => str_replace("%placeholder%", $reqValuesAllString, $query_prices)]);
		LOGDEB("Query Array for API:".$datas);

		$ch =  curl_init(Endpoint);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BEARER);
		curl_setopt($ch, CURLOPT_XOAUTH2_BEARER, $tb_config->Main->token);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);

		$results = curl_exec($ch);
		if ($results == false){
			http_response_code(404);
			notify(LBPCONFIGDIR, "tibber2mqtt", "Error in CURL Execution", "error");
			LOGERR("Error in CURL Execution: ".curl_error($results));
			return;
		}
		//Decode json result string to array
		$res = json_decode($results, TRUE);
		if(array_key_exists("errors", $res)){
			http_response_code(404);
			notify(LBPCONFIGDIR, "tibber2mqtt", "Tibber sent error message, see log for details.", "error");
			LOGERR("Tibber sent error message: ".json_encode($res["errors"]));
			return;
		}
		
		LOGDEB("Data retrieved from API:".json_encode($res));
			
		//Define empty arrays for following processing
		$processedPrices["today"] = array();
		$processedPrices["tomorrow"] = array();
		
		//Loop through received data
		foreach ($res["data"]["viewer"]["homes"][0]["currentSubscription"]["priceInfo"] as $keyofDay => $pricesofDay){
			foreach($reqValArrAll as $requestedValueKey){ //
				//Loop through all received prices and move - sorted by absolute hours - into processedPrices array
				foreach($pricesofDay as $priceRecord){
					
					//Provide as cents instead of Euro, except for PriceLevel
					if($tb_config->SendasCents == true && $requestedValueKey != "level"){
                        $priceRecord[$requestedValueKey] = $priceRecord[$requestedValueKey]*100;
                    }
					
					//Adjust LEVEL data to ensure compatibility with Loxone
                    if($requestedValueKey == "level"){
						switch ($priceRecord[$requestedValueKey]){
							case "VERY_CHEAP":
								$priceRecord[$requestedValueKey] = -2;
								break;
							case "CHEAP":
								$priceRecord[$requestedValueKey] = -1;
								break;						
							case "NORMAL":
								$priceRecord[$requestedValueKey] = 0;
                                break;
							case "EXPENSIVE":
								$priceRecord[$requestedValueKey] = 1;
								break;
							case "VERY_EXPENSIVE":
								$priceRecord[$requestedValueKey] = 2;
								break;
							default:
								$priceRecord[$requestedValueKey] = 9;// ERROR
								break;
						}
					}
						
					$processedPrices[$keyofDay][(int)date("H", strtotime($priceRecord["startsAt"]))][$requestedValueKey] = $priceRecord[$requestedValueKey];
				}
				
				//If no prices for $keyofDay (Today/tomorrow) have been received, set processedPrices array back to empty
				if(count($pricesofDay) == 0){
					for ($i=0; $i < 24; $i++){
						$processedPrices[$keyofDay][$i][$requestedValueKey] = "";
					}
				}
			}
		}
		
		//Save new version of cached file, as it has been reloaded
		file_put_contents(CacheFile, json_encode($processedPrices)); 
		LOGDEB("Data written to cache:".json_encode($processedPrices));
	}
	
	//Build relative array, if requested
	if($tb_config->RelativePrice == true){
		$processedPricesRel = array();
		$relativeHour = (int)date("H");
		$tempKeyofDay = "today";

		for($i=0; $i<24;$i++){
			foreach($reqRelativeValArr as $reqRelValArrKey){
				$processedPricesRel[$i][$reqRelValArrKey] = $processedPrices[$tempKeyofDay][$relativeHour][$reqRelValArrKey];
			}
			$relativeHour++;
			if($relativeHour == 24){
				$relativeHour = 0;
				$tempKeyofDay = "tomorrow";
			}
		}
	}

	//	Build Min Max if required
	if($tb_config->MinPrice == true || $tb_config->MaxPrice == true){
		$minPrice = $maxPrice = $processedPrices["today"][(int)date("H")]["total"];
		$minPriceAt = $maxPriceAt = 0;
		
		$tempKeyofDay = "today";
		
		for($i=(int)date("H"); $i<24;$i++){
			if($tempKeyofDay == "today") $newAt = (String)date("md").$i; else $newAt = (String)date("md", strtotime("+1 day")).$i;
			if($tb_config->MinPrice == true && $processedPrices[$tempKeyofDay][$i]["total"] != "" && $processedPrices[$tempKeyofDay][$i]["total"] < $minPrice){
				$minPrice = $processedPrices[$tempKeyofDay][$i]["total"];
				$minPriceAt = $newAt;
			}
			
			if($tb_config->MaxPrice == true && $processedPrices[$tempKeyofDay][$i]["total"] != "" && $processedPrices[$tempKeyofDay][$i]["total"] > $maxPrice){
				$maxPrice = $processedPrices[$tempKeyofDay][$i]["total"];
				$maxPriceAt = $newAt;
			}
			
			if($tempKeyofDay == "today" && $i == 23){
				$i = 0;
				$tempKeyofDay = "tomorrow";
			}
		}
	}
		
			
	// Get the MQTT Gateway connection details from LoxBerry
	$creds = mqtt_connectiondetails();
	 
	// Create MQTT client
	$client_id = uniqid(gethostname()."_client");
			 
	// Send data via MQTT
	$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'],  $creds['brokerport'], $client_id);
	if(!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])){
		http_response_code(404);
		notify(LBPCONFIGDIR, "tibber2mqtt", "Tibber2MQTT Plugin: MQTT connection failed", "error");
		LOGERR("MQTT connection failed");
		return;
	}
		
	//Send processed prices to MQTT one by one (Absolute)
	if($tb_config->AbsolutePrice == true){
		foreach ($processedPrices as $keyofDay => $pricesofDay){ //keyofDay=today/tomorrow | pricesofDay=24 arrays, 1 per hour 
			foreach($pricesofDay as $priceHour => $priceRecord){ //priceHour=00,01,etc. concrete hour for which prices are contained in array| priceRecord=array for a specific hour, containing x arrays for the requested values
				foreach($priceRecord as $priceRecordKey => $priceRecordValue){ //priceRecordKey=total,tax,etc. | priceRecordValue= price in cent belonging to Key
					$mqtt->publish("tibber/absprices/".$keyofDay."_".$priceHour."/".$priceRecordKey, $priceRecordValue, 0, 1);
				}
			}
		}
	}
	
	//Send processed prices to MQTT one by one (Relative)
	if($tb_config->RelativePrice == true){
		for($i=0; $i<24;$i++){
			foreach($reqRelativeValArr as $reqRelValArrKey){
				$mqtt->publish("tibber/relprices/".$i."/".$reqRelValArrKey, $processedPricesRel[$i][$reqRelValArrKey], 0, 1);
			}
		}
	}
	
	//Send Min/Max in addition to MQTT, if requested
	if($tb_config->MinPrice == true){
		$mqtt->publish("tibber/prices/min/total", $minPrice, 0, 1);
		$mqtt->publish("tibber/prices/min/at", $minPriceAt, 0, 1);
	}
	if($tb_config->MaxPrice == true){
		$mqtt->publish("tibber/prices/max/total", $maxPrice, 0, 1);
		$mqtt->publish("tibber/prices/max/at", $maxPriceAt, 0, 1);
	}
	
	$mqtt->close();
}

function clearcache(){
	LOGINF("Switched to clearcache");
	LOGTITLE("clearcache");
	file_put_contents(CacheFile, ""); 
}
?>