#!/usr/bin/php
<?php
/**
* This script is used to retrieve message from SBS source like Dump1090, Radarcape,.. or from phpvms, wazzup files,...
* If not used for SBS TCP source, this script can be used as cron job with $globalDaemon = FALSE
*/


require_once(dirname(__FILE__).'/../require/class.SBS.php');
// Check if schema is at latest version
require_once(dirname(__FILE__).'/../require/class.Connection.php');
require_once(dirname(__FILE__).'/../require/class.Common.php');

if (!isset($globalServerUser)) $globalServerUser = '';
if (!isset($globalServerPass)) $globalServerPass = '';
if (!isset($globalServer) || $globalServerUser == '' || $globalServerPass == '') {
	$globalServer = FALSE;
}
$globalServerHosts = 'sbs.flightairmap.fr:1001';

if (!isset($globalDebug)) $globalDebug = FALSE;

$schema = new Connection();
if ($schema::latest() === false) {
    echo "You MUST update to latest schema. Run install/index.php";
    exit();
}

$SBS=new SBS();

date_default_timezone_set('UTC');
// signal handler - playing nice with sockets and dump1090
if (function_exists('pcntl_fork')) {
    pcntl_signal(SIGINT,  function($signo) {
        global $sockets;
        echo "\n\nctrl-c or kill signal received. Tidying up ... ";
        die("Bye!\n");
    });
    pcntl_signal_dispatch();
}

// let's try and connect
if ($globalDebug) echo "Connecting to SBS ...\n";


function create_socket_to_server($host, $port, &$errno, &$errstr) {
    $ip = gethostbyname($host);
    $s = stream_socket_client("udp://".$host.':'.$port,$errno,$errstr,30);
	if ($s) return $s;
	else return false;
}

function create_socket($host, $port, &$errno, &$errstr) {
    $ip = gethostbyname($host);
    $s = socket_create(AF_INET, SOCK_STREAM, 0);
    if (socket_set_nonblock($s)) {
    //if (socket_set_block($s)) {
        $r = @socket_connect($s, $ip, $port);
        if ($r || socket_last_error() == 114 || socket_last_error() == 115) {
            return $s;
        }
    }
    $errno = socket_last_error($s);
    $errstr = socket_strerror($errno);
    socket_close($s);
    return false;
}

function connect_all($hosts) {
    global $sockets, $formats, $globalDebug;
    foreach ($hosts as $id => $host) {
	if (filter_var($host,FILTER_VALIDATE_URL)) {
            if (preg_match('/deltadb.txt$/',$host)) {
        	$formats[$id] = 'deltadbtxt';
            } else if (preg_match('/aircraftlist.json$/',$host)) {
        	$formats[$id] = 'aircraftlistjson';
            } else if (preg_match('/\/action.php\/acars\/data$/',$host)) {
        	$formats[$id] = 'phpvmacars';
            } else if (preg_match('/whazzup/',$host)) {
        	$formats[$id] = 'whazzup';
            } else if (preg_match('/recentpireps/',$host)) {
        	$formats[$id] = 'pirepsjson';
            } else if (preg_match(':data.fr24.com/zones/fcgi/feed.js:',$host)) {
        	// Desactivated. Here only because it's possible. Do not use without fr24 rights.
        	//$formats[$id] = 'fr24json';
            } else if (preg_match('/10001/',$host)) {
        	$formats[$id] = 'tsv';
            }
        } else {
	    $hostport = explode(':',$host);
    	    $s = create_socket($hostport[0],$hostport[1], $errno, $errstr);
	    if ($s) {
    	        $sockets[$id] = $s;
    	        if ($hostport[1] == '10001') {
        	    $formats[$id] = 'tsv';
		} elseif ($hostport[1] == '30002') {
        	    $formats[$id] = 'raw';
		} else $formats[$id] = 'sbs';
		if ($globalDebug) echo 'Connection in progress to '.$host.'....'."\n";
            } else {
		if ($globalDebug) echo 'Connection failed to '.$host.' : '.$errno.' '.$errstr."\n";
    	    }
        }
    }
}

if ($globalServer) {

	$serverhostport = explode(':',$globalServerHosts);
	$se = create_socket_to_server(gethostbyname($serverhostport[0]),$serverhostport[1], $errno, $errstr);
	if (!$se) {
		if ($globalDebug) echo 'Connection failed to '.$serverhostport[0].' : '.$errno.' '.$errstr."\n";
	}
}

if (isset($globalSBS1Hosts)) {
    $hosts = $globalSBS1Hosts;
} else {
    $hosts = array($globalSBS1Host.':'.$globalSBS1Port);
}
$status = array();
$sockets = array();
$formats = array();
$time = time();
$timeout = $globalSBS1TimeOut;
$errno = '';
$errstr='';
$_ = $_SERVER['_'];
//$globalDaemon = FALSE;
if (!isset($globalDaemon)) $globalDaemon = TRUE;
/* Initiate connections to all the hosts simultaneously */
connect_all($hosts);
// connected - lets do some work
if ($globalDebug) echo "Connected!\n";
sleep(1);
if ($globalDebug) echo "SCAN MODE \n\n";
if (!isset($globalCronEnd)) $globalCronEnd = 60;
$endtime = time()+$globalCronEnd;
$lastsend = time();
$i = 1;
$tt = 0;
while ($i > 0) {
    if (!$globalDaemon) $i = $endtime-time();
    foreach ($formats as $id => $value) {
	if ($value == 'deltadbtxt') {
	    $buffer = Common::getData($hosts[$id]);
    	    $buffer=trim(str_replace(array("\r\n","\r","\n","\\r","\\n","\\r\\n"),'\n',$buffer));
	    $buffer = explode('\n',$buffer);
	    foreach ($buffer as $line) {
    		if ($line != '') {
    		    $line = explode(',', $line);
	            $data = array();
	            $data['hex'] = $line[1]; // hex
	            $data['ident'] = $line[2]; // ident
	            $data['altitude'] = $line[3]; // altitude
	            $data['speed'] = $line[4]; // speed
	            $data['heading'] = $line[5]; // heading
	            $data['latitude'] = $line[6]; // lat
	            $data['longitude'] = $line[7]; // long
	            $data['verticalrate'] = ''; // vertical rate
	            $data['squawk'] = ''; // squawk
	            $data['emergency'] = ''; // emergency
		    $data['datetime'] = date('Y-m-d h:i:s');
		    $data['format_source'] = 'deltadbtxt';
    		    $SBS::add($data);
		    unset($data);
    		}
    	    }
	} elseif ($value == 'whazzup') {
	    $buffer = Common::getData($hosts[$id]);
    	    $buffer=trim(str_replace(array("\r\n","\r","\n","\\r","\\n","\\r\\n"),'\n',$buffer));
	    $buffer = explode('\n',$buffer);
	    foreach ($buffer as $line) {
    		if ($line != '') {
    		    $line = explode(':', $line);
    		    if (count($line) > 43) {
			$data = array();
			$data['id'] = $line[1].'-'.$line[0];
			$data['pilot_id'] = $line[1];
			$data['pilot_name'] = $line[2];
			$data['hex'] = str_pad(dechex($line[1]),6,'000000',STR_PAD_LEFT);
			$data['ident'] = $line[0]; // ident
			if ($line[7] != '' && $line[7] != 0) $data['altitude'] = $line[7]; // altitude
			$data['speed'] = $line[8]; // speed
			$data['heading'] = $line[45]; // heading
			$data['latitude'] = $line[5]; // lat
	        	$data['longitude'] = $line[6]; // long
	        	$data['verticalrate'] = ''; // vertical rate
	        	$data['squawk'] = ''; // squawk
	        	$data['emergency'] = ''; // emergency
			//$data['datetime'] = date('Y-m-d h:i:s');
			$data['datetime'] = date('Y-m-d h:i:s',strtotime($line[37])); // FIXME convert to correct format
		        $data['departure_airport_icao'] = $line[11];
		        $data['departure_airport_time'] = $line[22]; // FIXME put a :
		        $data['arrival_airport_icao'] = $line[13];
	    		//$data['arrival_airport_time'] = ;
	    		if ($line[9] != '') {
	    		    $aircraft_data = explode('/',$line[9]);
	    		    if (isset($aircraft_data[1])) {
	    			$data['aircraft_icao'] = $aircraft_data[1];
	    		    }
        		}
	    		$data['format_source'] = 'whazzup';
    			$SBS::add($data);
    			unset($data);
    		    }
    		}
    	    }
    	} elseif ($value == 'aircraftlistjson') {
	    $buffer = Common::getData($hosts[$id]);
	    $all_data = json_decode($buffer,true);
	    foreach ($all_data as $line) {
	        $data = array();
	        $data['hex'] = $line['hex']; // hex
	        $data['ident'] = $line['flight']; // ident
	        $data['altitude'] = $line['altitude']; // altitude
	        $data['speed'] = $line['speed']; // speed
	        $data['heading'] = $line['track']; // heading
	        $data['latitude'] = $line['lat']; // lat
	        $data['longitude'] = $line['lon']; // long
	        $data['verticalrate'] = $line['vrt']; // verticale rate
	        $data['squawk'] = $line['squawk']; // squawk
	        $data['emergency'] = ''; // emergency
		$data['datetime'] = date('Y-m-d h:i:s');
		$SBS::add($data);
	    }
    	} elseif ($value == 'fr24json') {
	    $buffer = Common::getData($hosts[$id]);
	    $all_data = json_decode($buffer,true);
	    foreach ($all_data as $key => $line) {
		if ($key != 'full_count' && $key != 'version' && $key != 'stats') {
		    $data = array();
		    $data['hex'] = $line[0];
		    $data['ident'] = $line[16]; //$line[13]
	    	    $data['altitude'] = $line[4]; // altitude
	    	    $data['speed'] = $line[5]; // speed
	    	    $data['heading'] = $line[3]; // heading
	    	    $data['latitude'] = $line[1]; // lat
	    	    $data['longitude'] = $line[2]; // long
	    	    $data['verticalrate'] = $line[15]; // verticale rate
	    	    $data['squawk'] = $line[6]; // squawk
	    	    $data['aircraft_icao'] = $line[8];
	    	    $data['registration'] = $line[9];
		    $data['departure_airport_iata'] = $line[11];
		    $data['arrival_airport_iata'] = $line[12];
	    	    $data['emergency'] = ''; // emergency
		    $data['datetime'] = date('Y-m-d h:i:s'); //$line[10]
		    $SBS::add($data);
		}
	    }
    	} elseif ($value == 'pirepsjson') {
	    $buffer = Common::getData($hosts[$id]);
	    $all_data = json_decode($buffer,true);
	    if (isset($all_data['pireps'])) {
	    foreach ($all_data['pireps'] as $line) {
	        $data = array();
	        $data['hex'] = str_pad(dechex($line['id']),6,'000000',STR_PAD_LEFT);
	        $data['ident'] = $line['callsign']; // ident
	        if (isset($line['pilotid'])) $data['pilot_id'] = $line['pilotid']; // pilot id
	        if (isset($line['name'])) $data['pilot_name'] = $line['name']; // pilot name
	        if (isset($line['alt'])) $data['altitude'] = $line['alt']; // altitude
	        if (isset($line['gs'])) $data['speed'] = $line['gs']; // speed
	        if (isset($line['heading'])) $data['heading'] = $line['heading']; // heading
	        $data['latitude'] = $line['lat']; // lat
	        $data['longitude'] = $line['lon']; // long
	        //$data['verticalrate'] = $line['vrt']; // verticale rate
	        //$data['squawk'] = $line['squawk']; // squawk
	        //$data['emergency'] = ''; // emergency
	        if (isset($line['depicao'])) $data['departure_airport_icao'] = $line['depicao'];
	        if (isset($line['deptime'])) $data['departure_airport_time'] = $line['deptime'];
	        if (isset($line['arricao'])) $data['arrival_airport_icao'] = $line['arricao'];
    		//$data['arrival_airport_time'] = $line['arrtime'];
	    	if (isset($line['aircraft'])) $data['aircraft_icao'] = $line['aircraft'];
	    	if (isset($line['transponder'])) $data['squawk'] = $line['transponder'];
		$data['datetime'] = date('Y-m-d h:i:s');
		if ($line['icon'] != 'ct') $SBS::add($data);
		unset($data);
	    }
	    }
    	} elseif ($value == 'phpvmacars') {
	    $buffer = Common::getData($hosts[$id]);
	    $all_data = json_decode($buffer,true);
	    foreach ($all_data as $line) {
	        $data = array();
	        $data['id'] = $line['id']; // id
	        $data['hex'] = str_pad(dechex($line['id']),6,'000000',STR_PAD_LEFT); // hex
	        if (isset($line['pilotname'])) $data['pilot_name'] = $line['pilotname'];
	        if (isset($line['pilotid'])) $data['pilot_id'] = $line['pilotid'];
	        $data['ident'] = $line['flightnum']; // ident
	        $data['altitude'] = $line['alt']; // altitude
	        $data['speed'] = $line['gs']; // speed
	        $data['heading'] = $line['heading']; // heading
	        $data['latitude'] = $line['lat']; // lat
	        $data['longitude'] = $line['lng']; // long
	        $data['verticalrate'] = ''; // verticale rate
	        $data['squawk'] = ''; // squawk
	        $data['emergency'] = ''; // emergency
	        $data['datetime'] = $line['lastupdate'];
	        $data['departure_airport_icao'] = $line['depicao'];
	        $data['departure_airport_time'] = $line['deptime'];
	        $data['arrival_airport_icao'] = $line['arricao'];
    		$data['arrival_airport_time'] = $line['arrtime'];
    		$data['aircraft_icao'] = $line['aircraft'];
	        $data['format_source'] = 'phpvmacars';
		$SBS::add($data);
		unset($data);
	    }
	} elseif ($value == 'sbs' || $value == 'tsv' || $value = 'raw') {
	    if (function_exists('pcntl_fork')) pcntl_signal_dispatch();

	    $read = $sockets;
	    $n = @socket_select($read, $write = NULL, $e = NULL, $globalSBS1TimeOut);
	    if ($n > 0) {
		foreach ($read as $r) {
        	    $buffer = socket_read($r, 3000,PHP_NORMAL_READ);
		    // lets play nice and handle signals such as ctrl-c/kill properly
		    //if (function_exists('pcntl_fork')) pcntl_signal_dispatch();
		    $dataFound = false;
		    $error = false;
		    //$SBS::del();
		    $buffer=trim(str_replace(array("\r\n","\r","\n","\\r","\\n","\\r\\n"),'',$buffer));
		    // SBS format is CSV format
		    if ($buffer != '') {
			$tt = 0;
			if ($value == 'raw') {
				// Not yet finished
				$hex = substr($buffer,1,-1);
				$bin = base_convert($hex,16,2);
				$df = intval(substr($bin,0,5),2);
				$ca = intval(substr($bin,6,3),2);
				echo date("Y-m-d").'T'.date("H:i:s.u")."    ".$hex."\n";
//				echo $bin."\n";
//				echo 'df : '.$df.' ( '.substr($bin,0,5).' )'."\n";
//				echo 'ca : '.$ca.' ( '.substr($bin,6,3).' )'."\n";
				if ($df == 17) {
					//echo $hex;
					$icao = substr($hex,2,6);
					$tc = intval(substr($bin,32,5),2);
					//echo 'icao : '.$icao.' - tc : '.$tc."\n";
					if ($tc >= 1 && $tc <= 4) {
						//callsign
						//strtr(,,'#ABCDEFGHIJKLMNOPQRSTUVWXYZ#####_###############0123456789######');
						$callsign = strtr(substr($bin,40,56),array('000001' => 'A','000010' => 'B','000011' => 'C','000100' => 'D','000101' => 'E','000110' => 'F','000111' => 'G','001000' => 'H','001001' => 'I','001010' => 'J','001011' => 'K','001100' => 'L','001101' => 'M', '001110' => 'N','001111' => 'O','010000' => 'P','010001' => 'Q','010010' => 'R','010011' => 'S','010100' => 'T','010101' => 'U','010110' => 'V','010111' => 'W','011000' => 'X','011001' => 'Y','011010' => 'Z'));
						echo 'icao : '.$icao.' - tc : '.$tc."\n";
						echo 'Callsign : '.$callsign;
					} elseif ($tc >= 9 && $tc <= 18) {
						// alt
						$latitude = intval(substr($bin,54,17),2);
						$longitude = intval(substr($bin,71,17),2);

						echo 'latitude : '.$latitude.' - longitude :'.$longitude;
					} elseif ($tc == 19) {
						// speed & heading
					}
				}
				
			} elseif ($value == 'tsv' || substr($buffer,0,4) == 'clock') {
			    $line = explode("\t", $buffer);
			    for($k = 0; $k < count($line); $k=$k+2) {
				$key = $line[$k];
			        $lined[$key] = $line[$k+1];
			    }
    			    if (count($lined) > 3) {
    				$data['hex'] = $lined['hexid'];
    				$data['datetime'] = date('Y-m-d h:i:s',strtotime($lined['clock']));;
    				if (isset($lined['ident'])) $data['ident'] = $lined['ident'];
    				if (isset($lined['lat']))$data['latitude'] = $lined['lat'];
    				if (isset($lined['lon']))$data['longitude'] = $lined['lon'];
    				if (isset($lined['speed']))$data['speed'] = $lined['speed'];
    				if (isset($lined['squawk']))$data['squawk'] = $lined['squawk'];
    				if (isset($lined['alt']))$data['altitude'] = $lined['alt'];
    				if (isset($lined['heading']))$data['heading'] = $lined['heading'];
    				$data['format_source'] = 'tsv';
    				$SBS::add($data);
    				unset($lined);
    				unset($data);
    			    } else $error = true;
			} else {
			    $line = explode(',', $buffer);
    			    if (count($line) > 20) {
    			    	$data['hex'] = $line[4];
    				$data['datetime'] = $line[8].' '.$line[7];
    				$data['ident'] = trim($line[10]);
    				$data['latitude'] = $line[14];
    				$data['longitude'] = $line[15];
    				$data['verticalrate'] = $line[16];
    				$data['emergency'] = $line[20];
    				$data['speed'] = $line[12];
    				$data['squawk'] = $line[17];
    				$data['altitude'] = $line[11];
    				$data['heading'] = $line[13];
    				$data['format_source'] = 'sbs';
    				$send = $SBS::add($data);
				//$send = $data;
    				unset($data);
    			    } else $error = true;
    			}
    			if ($error) {
    			    if (count($line) > 1 && ($line[0] == 'STA' || $line[0] == 'AIR' || $line[0] == 'SEL' || $line[0] == 'ID' || $line[0] == 'CLK')) { 
    				if ($globalDebug) echo "Not a message. Ignoring... \n";
    			    } else {
    				if ($globalDebug) echo "Wrong line format. Ignoring... \n";
    				if ($globalDebug) {
    				    echo $buffer;
    			    	    print_r($line);
    				}
				socket_close($r);
				connect_all($hosts);
			    }
    			}
			if ($globalServer && count($send) > 3 && time()-$lastsend > 1 && $value == 'sbs') {
				unset($send['archive_latitude']);
				unset($send['archive_longitude']);
				unset($send['livedb_latitude']);
				unset($send['livedb_longitude']);
				$send['datetime'] = strtotime($send['datetime']);
				$send['user'] = $globalServerUser;
				$send['pass'] = $globalServerPass;
				$send_data = json_encode($send);
				$salt = $globalServerPass;
				$send_data = $globalServerUser.':'.trim(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $salt, $send_data, MCRYPT_MODE_ECB, mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND))));
				fwrite($se,$send_data);
				unset($send);
				$lastsend = time();
			}
		    } else {
			$tt++;
			if ($tt > 5) {
			    if ($globalDebug)echo "ERROR : Reconnect...";
			    @socket_close($r);
			    sleep(2);
			    connect_all($hosts);
			    break;
			    $tt = 0;
			}
		    }
		}
	    } else {
		$error = socket_strerror(socket_last_error());
		if ($globalDebug) echo "ERROR : socket_select give this error ".$error . "\n";
		if (($error != SOCKET_EINPROGRESS && $error != SOCKET_EALREADY) || time() - $time >= $timeout) {
			if (isset($globalDebug)) echo "Restarting...\n";
			// Restart the script if possible
			if (is_array($sockets)) {
			    if ($globalDebug) echo "Shutdown all sockets...";
			    foreach ($sockets as $sock) {
				@socket_shutdown($sock,2);
				@socket_close($sock);
			    }
			}
/*
			if (function_exists('pcntl_exec') && $globalDaemon) {
			    echo "Script restart...";
			    pcntl_exec($_);
			} else {
*/
			    if ($globalDebug) echo "Restart all connections...";
			    sleep(2);
			    $time = time();
			    connect_all($hosts);
//			}
		}
	    }
	}
    }
}
//if (function_exists('pcntl_fork')) pcntl_exec($_,$argv);
?>