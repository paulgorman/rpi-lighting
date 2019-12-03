<?php
// Christmas 2019
// presence@irev.net

$rate = 100; // milliseconds  (2019: 100)
$gradient = 20; // how many steps from light to dark
$ratio = 20; // how often, in seconds, do we animate  (2019: 20)
$dimmest = 50; // how dim should dim be?  (2019:  50)

$stree = 255;		// dmx pack channel 1 south tree
$ntree = 255;		// dmx pack channel 2 north tree
$color = 255; 	// dmx pack channel 3 house color bulbs string
$white = 255;		// dmx pack channel 4 house white string

$direction_white = 0;
$direction_color = 0;
$animation_loop = 0;

date_default_timezone_set('America/Los_Angeles');
$offset = date_offset_get(date_create(date('Y-m-d'), timezone_open('America/Los_Angeles')))/60/60;
if (isset($argv[1])) {
	echo "offset: $offset\n";
	echo date("D M d Y"). ', sunrise time : ' .date_sunrise(time(), SUNFUNCS_RET_STRING, 47.318, -122.252, 90, $offset) . "\n";
	echo date("D M d Y"). ', sunset time : ' .date_sunset(time(), SUNFUNCS_RET_STRING, 47.318, -122.252, 90, $offset) . "\n";
}
/* sunset in seconds */
$sunrise = strtotime(date_sunrise(time(), SUNFUNCS_RET_STRING, 47.318, -122.252, 90, $offset));
$sunset = strtotime(date_sunset(time(), SUNFUNCS_RET_STRING, 47.318, -122.252, 90, $offset));

while (1==1) {
	$now = time();
	if (($now >= $sunset) && ($now >= $sunrise)) {
		evening();
	} elseif (($now <= $sunset) && ($now <= $sunrise)) {
		night();
	} elseif (($now <= $sunset) && ($now >= $sunrise)) {
		day();
	}
	usleep( $rate * 1000 ); // sleep in milliseconds
}

function lights ($a, $b, $c, $d) {
	echo date('s ') . str_pad("stree:$a",12) . str_pad("ntree:$b",12) . str_pad("color:$c",12) . "white:$d\n";
	shell_exec("/usr/bin/ola_streaming_client -u 1 -d $a,$b,$c,$d");
}

function evening() {
	global $white,$color,$stree, $ntree, $dimmest, $direction_white, $direction_color, $animation_loop, $gradient, $ratio;
	// color & white in/out animation
	// run the animation every 20 seconds
	$seconds = date('s');
	if ($seconds % $ratio == 0 || $animation_loop == 1) {
		$animation_loop = 1;
		// whites stays up, color cycles up
		if ($direction_white == 1 && $direction_color == 1) {
			$color = $color + $gradient;
			if ($color > 255) {
				$color = 255;
				$direction_color = 0;
				$direction_white = 0;
				$animation_loop = 0;
			}
		}
		// whites go up, color stays up
		if ($direction_white == 1 && $direction_color == 0) {
			$white = $white + $gradient;
			if ($white > 255) {
				$white = 255;
				$direction_white = 0;
				$direction_color = 1;
			}
		}
		// whites go down, color string stays up
		if ($direction_white == 0 && $direction_color == 0) {
			$white = $white - $gradient;
			if ($white < 0) {
				$white = 0;
				$direction_white = 1;
			}
		}
		// whites stays up, color cycles down
		if ($direction_white == 0 && $direction_color == 1) {
			$color = $color - $gradient;
			if ($color < 0) {
				$color = 0;
				$direction_color = 1;
				$direction_white = 1;
			}
		}
	}
	// trees on occasion toggle
	$stree = 255;
	$ntree = 255;
	lights($stree, $ntree, $color, $white);
}

function day() {
	global $white,$color,$stree,$ntree;
	// everyone out.
	$stree = 0;
	$ntree = 0;
	$color = 0;
	$white = 0;
	lights($stree, $ntree, $color, $white);
	sleep(1);
}

function night() {
	// dim everything a bit
	global $white,$color,$stree,$ntree, $dimmest;
	lights($stree, $ntree, $color, $white);
}

function breathe($input) {
	// glow around up and down original value
	// makes for nice flickering in incandescent strings
	// example: $white = rand(breathe($white)[0], breathe($white)[1]);
	$min = abs($input - 50);
	$max = $input + 50;
	if ($max > 255) {
		$max = 255;
	}
	return(array($min,$max));
}

function togglesecond($a,$b) {
	global $dimmest;
	$seconds = date('s');
	if ($seconds % 2 == 0) {
		$a = 0;
		$b = 255;
	} else {
		$a = 255;
		$b = $dimmest;
	}
	return (array($a,$b));
}
