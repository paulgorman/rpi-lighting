<?php
// Halloween 2019
// presence@irev.net

$rate = 100; // milliseconds  (20198: 100)
$ratio = 60; // how much of every 60 seconds are we nice  (2019: 50)
$dimmest = 50; // how dim should dim be?  (2019:  50)
$disco_nice = 110; // dmx value for pre-programmed blue/red flash
$disco_scary =  30;  // dmx value for pre-programmed red sweep

$grnpurp = 0;		// dmx pack channel 1
$stringtree = 0; 	// dmx pack channel 2
$oranges = 0;		// dmx pack channel 3
$red = 0;		// dmx pack channel 4
// disco mirror laser on channel 9

while (1==1) {
	if (date('s') < $ratio) {
		happy();
		echo ".";
	} else {
		scary();
		echo ",";
	}
	usleep( $rate * 1000 ); // sleep in milliseconds
}

function lights ($a, $b, $c, $d, $e) {
	//echo "grnpurp:$a\ttree:$b\toranges:$c\tred:$d\tdisco:$e\n";
	shell_exec("/usr/bin/ola_streaming_client -u 1 -d $a,$b,$c,$d,0,0,0,0,$e,0");
}

function happy() {
	global $grnpurp,$stringtree,$oranges, $red, $disco_nice;
	// tree on full
	$stringtree = 255;
	// have the strings of greens, oranges, and red all pulse	
	$grnpurp = rand(breathe($grnpurp)[0], breathe($grnpurp)[1]);
	$oranges = rand(breathe($oranges)[0], breathe($oranges)[1]);
	$red = rand(breathe($red)[0], breathe($red)[1]);
	lights($grnpurp, $stringtree, $oranges, $red, $disco_nice);
}

function scary() {
	global $grnpurp,$stringtree,$oranges, $red, $dimmest, $disco_scary;
	// tree toggle second on/off
	$seconds = date('s');
	if ($seconds % 2 == 0) {
		$stringtree = 0;
		$red = 255;
	} else {
		$stringtree = 255;
		$red = $dimmest;
	}
	// oranges out
	$oranges = 0;
	// green purple lines go nuts
	$grnpurp = rand(0,1);
	if ($grnpurp == 0) {
		$grnpurp = $dimmest;
	} else {
		$grnpurp = 255;
	}
	lights($grnpurp, $stringtree, $oranges, $red, $disco_scary);
}

function breathe($input) {
	// glow around up and down original value
	// makes for nice flickering in incandescent strings
	$min = abs($input - 50);
	$max = $input + 50;
	if ($max > 255) {
		$max = 255;
	}
	return(array($min,$max));
}
