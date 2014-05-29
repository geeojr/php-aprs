<?php
/*
	PHP-APRS
	Copyright 2014
	Jeremy R. Geeo <kd0eav@clear-sky.net>
*/



	class APRS
	{

		private $db = false;

		private $toLog = false;

		private $version = 'phpAPRS 0.03';
		private $software = 'APH003';
		private $ssid = 'NOCALL';
		private $pass = false;
		private $server = 'rotate.aprs.net';
		private $port = '14580';
		private $filter = 'm/250';
		private $lat = '0000.00N';
		private $lon = '00000.00W';
		private $comment = '{phpAPRS}';
		private $symbol  = '//';
		private $pos_int = 1800;
		private $obj_int = 600;

		public function __construct()
		{
		}

		function __destruct()
		{
			$this->flushLog();
		}

		public function flushLog()
		{
			if ( $this->toLog === false ) return;
			$fp = fopen( dirname( __FILE__ ).'/aprs.log' , 'a+' );
			if ( !is_resource( $fp ) ) return;
			fwrite( $fp , $this->toLog );
			fclose( $fp );
			$this->toLog = false;
		}

		private function log( $method , $details )
		{
			if ( is_array($details) ) $details = var_export( $details , true );

			$details = trim( $details );
			$this->toLog.= strftime( '%Y-%m-%d %H:%M:%S' )." [$method] $details\n";
			$this->flushLog();
		}


    public function connect()
    {
      $params = $this->getParams();

      $socket = @ socket_create( AF_INET , SOCK_STREAM , SOL_TCP );
      if ( $socket === false ) return "Can't create socket!";

      $conn = @ socket_connect( $socket  , $params['server'] ,  $params['port'] );
      if ( $conn === false ) return "Can't connect to server!";

      $login = "user {$params['ssid']} pass {$params['pass']} vers {$params['version']} filter {$params['filter']}\n";
      $this->log( __FUNCTION__ , "user {$params['ssid']} pass ****** vers {$params['version']} filter {$params['filter']}" );
      $send = @ socket_send( $socket  , $login , strlen($login) , 0 );
      if ( $send === false ) return "Can't send!";

      $servers = array( $socket );
      $last_tx = 0;

      while ( true )
      {
        $read = $servers;
        $wait = socket_select( $read , $write , $except , 5 );
        if ( $wait === false )
        {
          echo "# socket_select() failed, reason: " . socket_strerror(socket_last_error()) . "\n";
          break;
        }

        // Check for Self Transmit - can be important to keep filter functional;
        // Also update Opts from DB on self-transmit to avoid restart
        if ( $params['pos_int']>60 && time() - $last_tx > $params['pos_int'] )
        {
          $this->updateOpts();
          $params = $this->getParams();
          $last_tx = time();
          $data = "{$params['ssid']}>{$params['software']},TCPIP*:={$params['lat']}".substr( $params['symbol'] , 0 , 1 )."{$params['lon']}".substr( $params['symbol'] , 1 , 1 )."{$params['comment']} \n";
          $send = @ socket_send( $socket  , $data , strlen($data) , 0 );
          if ( $send === false )
          {
						$this->log( __FUNCTION__ , "socket_send() failed: " . socket_strerror(socket_last_error()) );
            echo "# socket_send() failed: " . socket_strerror(socket_last_error()) . "\n";
            break;
          }

          $packet = $this->recvPacket( $data );
        }

        // TODO: Send Pending Messages

        // Send Our Objects/Stations
        $stns = $this->getMyObjects( $params['obj_int'] );
        foreach( $stns as $data )
        {
          $send = @ socket_send( $socket  , $data , strlen($data) , 0 );
          if ( $send === false )
          {
						$this->log( __FUNCTION__ , "socket_send() failed: " . socket_strerror(socket_last_error()) );
            echo "# socket_send() failed: " . socket_strerror(socket_last_error()) . "\n";
            break;
          }

          $packet = $this->recvPacket( $data );
        }

        if ( $wait < 1 ) continue;

        foreach ( $read as $read_sock )
        {
          $recv = @ socket_read( $read_sock , 1024 );
          if ( $recv === false )
          {
						$this->log( __FUNCTION__ , "socket_read() failed: " . socket_strerror(socket_last_error()) );
            echo "# socket_read() failed: " . socket_strerror(socket_last_error()) . "\n";
            break;
          }
          $packet = $this->recvPacket( $recv );
        }
      }

      socket_close( $socket );
      fclose( $fp );

      return "Connection terminated";
    }


		public function updateOpts()
		{
			$db = $this->getDB();
			if ( $db === false ) return false;

			$sql = "SELECT `key`,value FROM opts ";
			$res = mysql_query( $sql , $db );
			while( $row = mysql_fetch_assoc( $res ) )
				$this->$row['key'] = $row['value'];
		}

		private function getDB()
		{
			if ( $this->db )
				if ( mysql_ping( $this->db ) )
					return $this->db;

			if ( $this->db = mysql_connect( 'localhost' , 'aprs_is' , '' ) )
				if ( mysql_select_db( 'aprs_is' , $this->db ) )
					return $this->db;

			return false;
		}

		public function getParams()
		{
			return array(
				'version' => $this->version ,
				'software'=> $this->software ,
				'ssid'    => $this->ssid ,
				'pass'    => $this->pass ,
				'server'  => $this->server ,
				'port'    => $this->port ,
				'filter'  => $this->filter ,
				'lat'     => $this->lat ,
				'lon'     => $this->lon ,
				'comment' => $this->comment ,
				'symbol'  => $this->symbol ,
				'pos_int'  => $this->pos_int ,
				'obj_int'  => $this->obj_int
			);
		}

		public function getSend()
		{
			if ( ! $db=$this->getDB() ) return false;

			$sql = "SELECT id, data FROM txQ LIMIT 1";
			$res = mysql_query( $sql , $db );
			if( $row = mysql_fetch_assoc( $res ) )
			{
				$sql = "DELETE FROM txQ WHERE id={$row['id']}";
				mysql_query( $sql , $db );
				return $row['data'];
			}
			return false;
		}

		public function send( $data )
		{
			if ( ! $db=$this->getDB() ) return false;

			$c = mysql_real_escape_string( $data );
			$sql = "INSERT INTO txQ SET data='$c' ";
			return mysql_query( $sql , $db );
		}

		public function recvPacket( $packet )
		{
			$this->log( __FUNCTION__ , $packet );

			$db = $this->getDB();
			if ( $db === false ) return false;

			$parse = $this->parsePacket( $packet );
			if ( $parse === false ) return false;

			$src = mysql_real_escape_string( $parse['src'] );
			$ssid = mysql_real_escape_string( $parse['ssid'] );
/*
			$p = mysql_real_escape_string( $packet );
			$sql = "INSERT INTO recv SET timestamp=UNIX_TIMESTAMP(), src='$src', ssid='$ssid', packet='$p' ";
			mysql_query( $sql , $db );
*/

			$sql = "INSERT INTO stations SET ssid='$ssid' ";
			mysql_query( $sql , $db );
			$sql = "UPDATE stations SET timestamp=UNIX_TIMESTAMP(), ";
			if ( $parse['src'] != '' ) $sql.= "src='{$parse['src']}', ";
			if ( $parse['destination'] != '' ) $sql.= "destination='{$parse['destination']}', ";
			if ( $parse['path'] != '' ) $sql.= "path='{$parse['path']}', ";
			if ( $parse['lat'] != '' ) $sql.= "lat='{$parse['lat']}', ";
			if ( $parse['lon'] != '' ) $sql.= "lon='{$parse['lon']}', ";
			if ( $parse['msg_capable'] === true ) $sql.= "msg_capable='Y', ";
			if ( $parse['kill'] === true ) $sql.= "`kill`='Y', "; else $sql.= "`kill`='N', ";
			if ( $parse['symbol'] != '' ) $sql.= "symbol='".mysql_real_escape_string($parse['symbol'])."', ";
			if ( $parse['station_type'] != '' ) $sql.= "station_type='{$parse['station_type']}', ";
			if ( $parse['status'] != '' ) $sql.= "status='{$parse['status']}', ";
			if ( $parse['capabilities'] != '' ) $sql.= "capabilities='{$parse['capabilities']}', ";
			if ( $parse['comment'] != '' ) $sql.= "comment='".mysql_real_escape_string($parse['comment'])."', ";
			$sql = substr( $sql , 0 , -2 )." WHERE ssid='$ssid' ";
			mysql_query( $sql , $db );

			return $parse;
		}


		public function getMyObjects( $interval )
		{
			$db = $this->getDB();
			if ( $db === false ) return false;

			$r = array();

			$sql = "SELECT `name`, symbol, lat, lon, comment, `kill` FROM objects ";
			$sql.= "WHERE UNIX_TIMESTAMP()-timestamp > $interval OR `kill` = 'Y' ";
			$res = mysql_query( $sql , $db );
			while( $row = mysql_fetch_assoc( $res ) )
			{
				$r[] = $this->createObject( $row['name'] , $row['symbol'] , $row['lat'] , $row['lon'] , $row['comment'] , $row['kill'] );
				if ( $row['kill'] == 'Y' )
					$sql = "DELETE FROM objects WHERE `name` = '{$row['name']}' ";
				else
					$sql = "UPDATE objects SET timestamp=UNIX_TIMESTAMP() WHERE `name` = '{$row['name']}' ";
				mysql_query( $sql , $db );
			}
			return $r;
		}

		//2013-07-24 02:24:46 [recvPacket] PY5VAN>APU25N,qAR,PY5YAM-1::PY5FOC   :ja  alterei por  aqui{81
		//2013-07-24 02:24:49 [recvPacket] PY5FOC>APU25N,TCPIP*,qAC,T2BRAZIL::PY5VAN   :ack81
		public function sendMessage( $from , $to , $message )
		{
			$params = $this->getParams();
			$ack = $this->getNextAck();
			$to = strtoupper(str_pad(substr($to,0,9),9));
			$from = strtoupper($from);
			$data = "{$from}>{$params['software']},TCPIP*:";
			$data.= ":{$to}:{$message}{{$ack}\n";
			$this->send( $data );
			$this->recvPacket( $data );
		}
		public function sendAck( $from , $to , $ack )
		{
			$params = $this->getParams();
			$to = strtoupper(str_pad(substr($to,0,9),9));
			$from = strtoupper($from);
			$data = "{$from}>{$params['software']},TCPIP*:";
			$data.= ":{$to}:ack{$ack}\n";
			$this->send( $data );
			$this->recvPacket( $data );
		}

		private function getNextAck()
		{
			$ackfile = dirname( __FILE__ ).'/.aprs_ack';
			if ( file_exists($ackfile) ) $ack = file_get_contents( $ackfile );
			else $ack = 0;

			$dec = base_convert( $ack , 36 , 10 ) + 1;
			$ack = base_convert( $dec , 10 , 36 );
			if ( strlen( $ack ) > 5 ) $ack = 0;

			file_put_contents( $ackfile , $ack );
			return $ack;
		}


		private function createObject( $name , $symbol , $lat , $lon , $comment , $kill )
		{
			$params = $this->getParams();

			$name = sprintf( "%-9s" , $name );

			if ( $kill == 'Y' ) $kill = '_';
			else $kill = '*';

			$dtime = strftime( "%d%H%Mz" );

			if ( $lat > 0 ) $ns = 'N';
			else $ns = 'S';
			$lat = sprintf( "%07.2f" , $this->decToAPRS( $lat ) ).$ns;

			if ( $lon > 0 ) $ew = 'E';
			else $ew = 'W';
			$lon = sprintf( "%08.2f" , $this->decToAPRS( $lon ) ).$ew;

			// ;"$name"*092345z4903.50N/07201.75W
			$data = "{$params['ssid']}>{$params['software']},TCPIP*:";
			$data.= ";{$name}{$kill}{$dtime}{$lat}".substr( $params['symbol'] , 0 , 1 )."{$lon}".substr( $params['symbol'] , 1 , 1 )."{$comment} \n";

			return "$data";
		}

		private function decToAPRS( $value )
		{
			$value = abs(floatval( $value ));
			if ( $value == 0 ) return false;

			$deg = abs(intval( $value ));
			$dec = sprintf( "%05.2f" ,  round(($value-$deg)*60,2) );
			return "{$deg}{$dec}";
		}

		public function parsePacket( $packet )
		{
			if ( substr( $packet , 0 , 1 ) == '#' ) return false;

			$s = false;
			$station_type = false;
			$symbol = false;
			$time = false;
			$lat = false;
			$lon = false;
			$msg_to = false;
			$msg = false;
			$ack = false;
			$comment = false;
			$status = false;
			$capabilities = false;
			$kill = false;
			$alt = false;
			$course = false;
			$speed = false;
			$telem = false;

			$nl = strpos( $packet , "\n" );
			if ( $nl !== false )
				$packet = substr( $packet , 0 , $nl-1 );

			$r['src'] = substr( $packet , 0 , strpos( $packet , '>' ) );
			$packet = substr( $packet , strlen($r['src'])+1 );

			$r['destination'] = substr( $packet , 0 , strpos( $packet , ',' ) );
			$packet = substr( $packet , strlen($r['destination'])+1 );

			$r['path'] = substr( $packet , 0 , strpos( $packet , ':' ) );
			$packet = substr( $packet , strlen($r['path'])+1 );

			$r['type'] = substr( $packet , 0 , 1 );
			$packet = substr( $packet , 1 );

			$m = false;
			switch( $r['type'] )
			{
				case '!':
					$t = 'position';
					$n = 'Position w/o timestamp';
					$station_type = 'station';
					break;
				case '=':
					$t = 'position';
					$n = 'Position w/o timestamp - msg capable';
					$station_type = 'station';
					$m = true;
					break;
				case '/':
					$t = 'position_time';
					$n = 'Position w/ timestamp';
					$station_type = 'station';
					break;
				case '@':
					$t = 'position_time';
					$n = 'Position w/ timestamp - msg capable';
					$station_type = 'station';
					$m = true;
					break;
				case '>':
					$t = 'status';
					$n = 'Status';
					break;
				case '<':
					$t = 'capabilities';
					$n = 'Station Capabilities';
					break;
				case '#':
				case '*':
					$t = 'weather';
					$n = 'WX';
					break;
				case '_':
					$t = 'weather';
					$n = 'WX w/o position';
					break;
				case '$':
					$t = 'gps';
					$n = 'Raw GPS';
					break;
				case ')':
					$t = 'item';
					$n = 'Item';
					$station_type = 'item';
					break;
				case ';':
					$t = 'object';
					$n = 'Object';
					$station_type = 'object';
					break;
				case ':':
					$t = 'message';
					$n = 'Message';
					break;
				case '`':
					$t = 'mic-e';
					$n = 'Mic-E Data (current)';
					break;
				case "'":
					$t = 'mic-e';
					$n = 'Mic-E Data (old/D-700)';
					break;
				default:
					$t = '';
					$n = 'OTHER/UNKNOWN';
			}

			if ( $t == 'position' )
			{
				if ( is_numeric( substr( $packet , 0 , 1 ) ) )
				{
					// 3901.00N/09433.47WhPHG7330 W2, MOn-N RMC, mary.young@hcamidwest.com
					$lat = intval(substr( $packet , 0 , 2 )) + substr( $packet , 2 , 5 )/60;
					if ( substr( $packet , 7 , 1 ) == 'S' ) $lat = -$lat;

					$lon = intval(substr( $packet , 9 , 3 )) + substr( $packet , 12 , 5 )/60;
					if ( substr( $packet , 17 , 1 ) == 'W' ) $lon = -$lon;

					$symbol = substr( $packet , 8 , 1 ).substr( $packet , 18 , 1 );
					$comment = substr( $packet , 19 );
				}
				else
				{ // /:\{s6T`U>R:G/A=001017 13.8V Jeremy kd0eav@clear-sky.net
					$clat = substr( $packet , 1 , 4 );
					$lat = 90 - ( (ord($clat[0])-33)*pow(91,3) + (ord($clat[1])-33)*pow(91,2) + (ord($clat[2])-33)*91 + ord($clat[3])-33 ) / 380926;

					$clon = substr( $packet , 5 , 4 );
					$lon= -180 + ( (ord($clon[0])-33)*pow(91,3) + (ord($clon[1])-33)*pow(91,2) + (ord($clon[2])-33)*91 + ord($clon[3])-33 ) / 190463;

					$symbol = substr( $packet , 0 , 1 ).substr( $packet , 9 , 1 );

					$cs = substr( $packet , 10 , 2 );
					if ( substr( $cs , 0 , 1 ) != ' ' )
					{
						// TODO: figure out course/speed or alt or range
						$ctype = substr( $packet , 12 , 1 );

					}
					$comment = substr( $packet , 13 );
				}
			}
			if ( $t == 'position_time' )
			{
				// 202051z3842.05N/09317.07W_308/009g017t026r000p000P000h75b10173L021.DsVP
				$lat = intval(substr( $packet , 7 , 2 )) + substr( $packet , 9 , 5 )/60;
				if ( substr( $packet , 15 , 1 ) == 'S' ) $lat = -$lat;

				$lon = intval(substr( $packet , 16 , 3 )) + substr( $packet , 19 , 5 )/60;
				if ( substr( $packet , 24 , 1 ) == 'W' ) $lon = -$lon;

				$symbol = substr( $packet , 15 , 1 ).substr( $packet , 25 , 1 );
				$comment = substr( $packet , 26 );
			}
			if ( $t == 'object' )
			{ // 146.79-KC*202142z3917.54N/09434.49WrKC Northland ARES / Clay Co ARC T107.2
				$s = substr( $packet , 0 , 9 );

				if ( substr( $packet , 9 , 1 ) == '_' )
					$kill = true;

				$lat = intval(substr( $packet , 17 , 2 )) + substr( $packet , 19 , 5 )/60;
				if ( substr( $packet , 25 , 1 ) == 'S' ) $lat = -$lat;

				$lon = intval(substr( $packet , 26 , 3 )) + substr( $packet , 29 , 5 )/60;
				if ( substr( $packet , 34 , 1 ) == 'W' ) $lon = -$lon;

				$symbol = substr( $packet , 25 , 1 ).substr( $packet , 35 , 1 );
				$comment = substr( $packet , 36 );
			}
			if ( $t == 'item' )
			{ // 146.79-KC!3917.54N/09434.49WrKC Northland ARES / Clay Co ARC T107.2

				$offset = strpos( $packet , '!' );
				if ( $offset === false )
				{
					$offset = strpos( $packet , '_' );
					if ( $offset === false ) return false;
					else $kill = true;
				}

				$s = substr( $packet , 0 , $offset );

				$lat = intval(substr( $packet , $offset+1 , 2 )) + substr( $packet , $offset+3 , 5 )/60;
				if ( substr( $packet , $offset+8 , 1 ) == 'S' ) $lat = -$lat;

				$lon = intval(substr( $packet , $offset+10 , 3 )) + substr( $packet , $offset+13 , 5 )/60;
				if ( substr( $packet , $offset+18 , 1 ) == 'W' ) $lon = -$lon;

				$symbol = substr( $packet , $offset+9 , 1 ).substr( $packet , $offset+19 , 1 );
				$comment = substr( $packet , $offset+20 );
			}
			if ( $t == 'message' )
			{
				$msg_to = trim(substr( $packet , 0 , 9 ));
				if ( substr( $msg_to , 0 , 3 ) == 'BLN' )
				{
					if ( is_numeric(substr( $msg_to , 3 , 1 ) ) )
						$n = 'Bulletin';
					else
						$n = 'Announcement';
					$msg = substr( $packet , 10 );
				}
				else
				{
					$pos = strpos( $packet , '{' );
					if ( $pos !== false ) $ack = substr( $packet , $pos+1 );
					else $ack = '';
					$msg = substr( $packet , 10 , -(strlen($ack)+1) );

					if ( substr( $msg , 0 , 3 ) == 'ack' ) $n = 'Message Acknowledge';
					if ( substr( $msg , 0 , 3 ) == 'rej' ) $n = 'Message Reject';
					if ( $n != 'Message' ) $ack = substr( $msg , 3 );
				}
			}
			if ( $t == 'mic-e' )
			{
				for( $i=0 ; $i<7 ; $i++ )
					$lat_dig[$i] = $this->miceDecode(substr( $r['destination'] , $i , 1 ));

				$lat = intval($lat_dig[0]['dig'].$lat_dig[1]['dig'])
								+ ($lat_dig[2]['dig'].$lat_dig[3]['dig'].'.'.$lat_dig[4]['dig'].$lat_dig[5]['dig'])/60;
				if ( $lat_dig[3]['ns'] == 'S' ) $lat = -$lat;

				$lon = (ord(substr( $packet , 0 , 1 ))-28) + $lat_dig[4]['off']
								+ ((ord(substr( $packet , 1 , 1 ))-28) +
									((ord(substr( $packet , 2 , 1 ))-28) * .01) ) /60;
				if ( $lat_dig[5]['we'] == 'W' ) $lon = -$lon;

				$symbol = substr( $packet , 7 , 1 ).substr( $packet , 6 , 1 );
				$comment = substr( $packet , 8 );
			}

			if ( $t == 'status' )
				$status = $packet;

			if ( $t == 'capabilities' )
				$capabilities = $packet;

			if ( $s == '' ) $r['ssid'] = $r['src'];
			else $r['ssid'] = $s;
			$r['station_type'] = $station_type;
			$r['symbol'] = $symbol;
			$r['type_name'] = $n;
			$r['msg_capable'] = $m;
			$r['time'] = $time;
			$r['lat'] = $lat;
			$r['lon'] = $lon;
			$r['msg_to'] = $msg_to;
			$r['msg'] = $msg;
			$r['ack'] = $ack;
			$r['comment'] = trim($comment);
			$r['status'] = trim( $status );
			$r['capabilities'] = trim( $capabilities );
			$r['kill'] = $kill;
			$r['alt'] = $alt;
			$r['course'] = $course;
			$r['speed'] = $speed;
			$r['telem'] = $telem;

			$r['remain'] = $packet;

			$this->log( __FUNCTION__ , $r );

			return $r;
		}

		private function miceDecode( $in )
		{
			if ( strlen( $in ) > 1 ) return false;
			$v = ord( $in );
			$r = array();

			if ( ($v > 47 && $v < 58) || $v == 76 )
			{
				if ( $v == 76 ) $r['dig'] = '';
				else $r['dig'] = $v-48;
				$r['msg'] = '0';
				$r['ns']  = 'S';
				$r['off'] = 0;
				$r['we']  = 'E';
			}
			if ( $v > 64 && $v < 76 )
			{
				if ( $v == 75 ) $r['dig'] = '';
				else $r['dig'] = $v-65;
				$r['msg'] = '1 Custom';
				$r['ns']  = '';
				$r['off'] = '';
				$r['we']  = '';
			}
			if ( $v > 79 && $v < 91 )
			{
				if ( $v == 90 ) $r['dig'] = '';
				else $r['dig'] = $v-80;
				$r['msg'] = '1 Std';
				$r['ns']  = 'N';
				$r['off'] = 100;
				$r['we']  = 'W';
			}
			return $r;
		}


		public function getStations( $expire=1800 )
		{
			$db = $this->getDB();
			if ( $db === false ) return false;

			$r = array();

			$sql = "SELECT ssid, src, destination, path, station_type, symbol, timestamp, lat, lon, comment, status, capabilities, msg_capable, `kill` FROM stations ";
			$sql.= "WHERE timestamp > UNIX_TIMESTAMP() - $expire AND lat IS NOT NULL AND lon IS NOT NULL AND ssid <> '' AND src <> '' ";
			$sql.= "ORDER BY timestamp DESC ";
			$res = @ mysql_query( $sql , $db );
			if ( $res === false ) return $r;
			while( $row = mysql_fetch_assoc( $res ) )
				$r[ $row['ssid'] ] = $row;

			return $r;
		}

		public function getExpiredStations( $expire=1800 )
		{
			$db = $this->getDB();
			if ( $db === false ) return false;

			$r = array();

			$sql = "SELECT ssid, src, destination, path, station_type, symbol, timestamp, lat, lon, comment, status, capabilities, msg_capable, `kill` FROM stations ";
			$sql.= "WHERE timestamp < UNIX_TIMESTAMP() - $expire AND timestamp > UNIX_TIMESTAMP() - $expire*2 AND lat IS NOT NULL AND lon IS NOT NULL ";
			$sql.= "ORDER BY timestamp DESC ";
			$res = @ mysql_query( $sql , $db );
			if ( $res === false ) return $r;
			while( $row = mysql_fetch_assoc( $res ) )
				$r[ $row['ssid'] ] = $row;

			return $r;
		}

	}

?>