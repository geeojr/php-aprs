#!/usr/bin/php
<?php
/*
	PHP-APRS
	Copyright 2014
	Jeremy R. Geeo <kd0eav@clear-sky.net>
*/

	date_default_timezone_set("US/Central");

	require_once( 'aprs.php' );
	$aprs = new APRS();
	$aprs->connect();
?>