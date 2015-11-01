#!/usr/bin/php
<?
/*
	At sundown, turn on home exterior DMX lighting
	At midnight, dim back lighting
	At sunrise, turn lighting off
	Crontab:
		* * * * * ~/lighting/sunset.php > /dev/null 2>&1
	DMX Dimmer pack on channels 1 and 2
	Uses "open lighting architecture" on raspberry pi
	http://opendmx.net/index.php/Open_Lighting_Architecture

	- Presence
*/

/* Figure out timezone offset, in hours, from GMT, watching for DST and standard time */
date_default_timezone_set('America/Los_Angeles');
$offset = date_offset_get(date_create(date('Y-m-d'), timezone_open('America/Los_Angeles')))/60/60;
//echo "offset: $offset\n";
//echo date("D M d Y"). ', sunrise time : ' .date_sunrise(time(), SUNFUNCS_RET_STRING, 36.1215, -115.1739, 90, $offset) . "\n";
//echo date("D M d Y"). ', sunset time : ' .date_sunset(time(), SUNFUNCS_RET_STRING, 36.1215, -115.1739, 90, $offset) . "\n";

/* sunset in seconds */
$sunrise = strtotime(date_sunrise(time(), SUNFUNCS_RET_STRING, 36.1215, -115.1739, 90, $offset));
$sunset = strtotime(date_sunset(time(), SUNFUNCS_RET_STRING, 36.1215, -115.1739, 90, $offset));

$now = time();

if (($now >= $sunset) && ($now >= $sunrise)) {
	echo "go sunset\n";
	system("/usr/local/bin/ola_streaming_client -u 1 -d 150,100,0,0");
} elseif (($now <= $sunset) && ($now <= $sunrise)) {
	echo "night\n";
	system("/usr/local/bin/ola_streaming_client -u 1 -d 70,50,0,0");
} elseif (($now <= $sunset) && ($now >= $sunrise)) {
	echo "go sunrise\n";
	system("/usr/local/bin/ola_streaming_client -u 1 -d 0,0,0,0");
}
