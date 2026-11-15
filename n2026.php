#!/usr/bin/env php
<?php
// November 2026 - holiday-neutral lanterns + orange/red puck (no purple, neon always on)
// Talks to olad's HTTP API (one persistent curl handle) instead of
// spawning ola_streaming_client thousands of times.
//
// Run:      php n2026.php
// Override: echo full    > /tmp/lanterns.mode   (kid-friendly steady glow)
//           echo flicker > /tmp/lanterns.mode
//           echo off     > /tmp/lanterns.mode
//           echo auto    > /tmp/lanterns.mode   (back to the normal cycle)
//
// Needs: php-cli, php-curl, olad running with its web server on :9090

// ---------- config ----------
const OLA_URL      = 'http://127.0.0.1:9090/set_dmx';
const UNIVERSE     = 1;
const FPS          = 30;
const MODE_FILE    = '/tmp/lanterns.mode';
const FADE_SECS    = 2.0;     // crossfade between modes
const BREADTH      = 60;      // How deep a dim/bright candle flicker will go

const DEBUG       = true;
const DEBUG_EVERY = 3;     // 1 = every frame, 3 = ~10 lines/sec

const LANTERNS     = 12;      // DMX channels 1..12
const PUCK_MASTER  = 20;      // 21=R 22=G 23=B
const LAST_CH      = 23;			// last in-use DMX channel so we don't blast the entire universe

// "neon" fixtures: strictly 0 or 255, never dimmed, never crossfaded.
// These channels are skipped by the lantern flicker. Add a second channel here later.
const NEON_CHANNELS = [9];
const NEON_MIN_HOLD = 0.10;   // seconds: fixture needs this long between ANY two changes
const NEON_GAP_MIN  = 6.0;    // steady-on time between dropouts (flicker mode)
const NEON_GAP_MAX  = 10.0;

const DIM_FLOOR    = 30;      // dimmest a flickering lantern gets

// RGB puck: orange <-> red only
const PUCK_ORANGE_GREEN = 100;  // green level at full orange (red is always 255, blue always 0). Higher = more yellow
const PUCK_FADE_SPEED   = 0.5;  // how fast orange<->red fades; smaller = gentler

// ---------- helpers ----------


// frand(low, high) = a random floating point number between low and high.
//   frand(0.04, 0.20) might return 0.1273
//
// mt_rand(low, high) only returns WHOLE numbers
//   I want a float for things like "wait 0.07 seconds".
//
// Get a random whole number, turn it into a fraction between
// 0.0 and 1.0, then stretch that fraction across the range we want.
// function frand($a, $b) { return $a + mt_rand() / mt_getrandmax() * ($b - $a); }
function frand($low, $high) {
    $biggest_possible = mt_getrandmax();   // largest number mt_rand() can return (~2.1 billion, 2,147,483,647)
    $random_whole     = mt_rand();         // random whole number from 0 up to largest possible the box can provide
    $fraction         = $random_whole / $biggest_possible;   // decimal from 0.0 to 1.0
    $range_width = $high - $low;           // how far apart the two limits are
    return $low + ($fraction * $range_width);   // fraction 0.0 gives $low, 1.0 gives $high
}

// force value to stay inside DMX range 0 - 255, 
// if goes out side of bounds, drop to zero or top out at 255.
function clamp($v, $lo = 0, $hi = 255) { return max($lo, min($hi, $v)); }

function blankFrame() { return array_fill(1, LAST_CH, 0.0); }

function readOverride() {
    $m = @trim(@file_get_contents(MODE_FILE));
    return in_array($m, ['auto', 'flicker', 'full', 'off'], true) ? $m : 'auto';
}

$ch = curl_init(OLA_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT_MS     => 200,
    CURLOPT_CONNECTTIMEOUT_MS => 200,
    CURLOPT_TCP_NODELAY    => true,
]);

function sendDmx($ch, array $frame) {
    static $failures = 0;
    static $lastReport = 0;

    $vals = [];
    for ($c = 1; $c <= LAST_CH; $c++) {
        $vals[] = (int) round(clamp($frame[$c]));
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'u=' . UNIVERSE . '&d=' . implode(',', $vals));

    $started = microtime(true);
    $result  = curl_exec($ch);
		// Each POST every 30miliseconds is like 10 to 15 KB/s over loopback
		// CURL defaults to keeping the connection open for repeated POSTs

		// confirm we're using the one connection, not opening up a zillion
		$new_connections = curl_getinfo($ch, CURLINFO_NUM_CONNECTS);
		if ($new_connections > 0) fprintf(STDERR, "%s opened a NEW connection\n", date('H:i:s'));

    $ms      = (microtime(true) - $started) * 1000;
    if ($result === false || $ms > 50) {
        $failures++;
        if (microtime(true) - $lastReport >= 1.0) {
            fprintf(STDERR, "%s SEND PROBLEM x%d: %s (%.0f ms)\n", date('H:i:s'), $failures,
                $result === false ? curl_error($ch) : 'slow', $ms);
            $failures = 0;
            $lastReport = microtime(true);
        }
    }
}

// ---------- flicker state (one "candle" per lantern, plus one for the puck) ----------
// Each candle has its own speed, so no two lanterns flicker alike.
$candles = [];
for ($i = 0; $i < LANTERNS + 1; $i++) {
    $candles[$i] = [
        'cur'   => frand(120, 255),
        'tgt'   => 200,
        'next'  => 0,
        'speed' => frand(0.6, 1.7),   // personality
        'depth' => frand(0.6, 1.0),   // how deep this one's dips go
    ];
}

function stepCandle(array &$k, $now, $dt) {
	/*
		// Claude picks the target from scratch each time, independent of where it is now. 
		// Most targets land in 130 to 255, with a 12% chance of a dip down to 30 to 110. 
		// cur then eases toward tgt, so tgt is where it's heading and cur is what gets sent. 
		// That means mine can swing about 125 steps at once, and dips can be much bigger.
    if ($now >= $k['next']) {
        // mostly bright-ish, occasionally a big dip (a gust of wind)
        if (mt_rand(0, 99) < 12) {
            $k['tgt'] = frand(DIM_FLOOR, 110);
        } else {
            $k['tgt'] = frand(130, 255);
        }
        $k['tgt'] = 255 - (255 - $k['tgt']) * $k['depth'];
        $k['next'] = $now + frand(0.04, 0.20) / $k['speed'];
    }
	*/

	// Presence's version is my old "only flicker within a window of the current brightness"
	// Because Presence thinks that too big of a brightness change is tacky, and a more controlled
	// flickering dimming range looks more pleasant.  My mystical flame versus chaotic nature.
	if ($now >= $k['next']) {
    // wander +/- BREADTH from where we are, with a gentle pull toward the middle
    $step = frand(-BREADTH, BREADTH) * $k['depth'];
		// this 0.1 is the pull to medium brightness
    $k['tgt'] = clamp($k['tgt'] + $step + (190 - $k['tgt']) * 0.1, DIM_FLOOR, 255);
    $k['next'] = $now + frand(0.04, 0.20) / $k['speed'];
	}
	// End of the Presence vs Claude option, back to the core:
	/*
		$k['cur'] += ...;        // moves the brightness along
		$k['tgt'] = ...;         // picks a new target
		$k['next'] = ...;        // schedules the next target change
		using ampersand $k so we write candle's memory as we cruise along
	*/
    $rate = 14 * $k['speed'];
		// smoothing:  move the actual brightness ($cur) to the target ($tgt) in the remaining time ($dt)
		// Ease the brightness we actually send (cur) toward the brightness
		// we're aiming for (tgt), covering a fraction of the remaining distance each frame.
		$distance_left   = $k['tgt'] - $k['cur'];
		$fraction_to_move = $rate * $dt;            // bigger rate or longer frame = bigger step
		if ($fraction_to_move > 1.0) {
		    $fraction_to_move = 1.0;                // never overshoot the target
		}
		$k['cur'] = $k['cur'] + ($distance_left * $fraction_to_move);
    // code golf: $k['cur'] += ($k['tgt'] - $k['cur']) * min(1.0, $rate * $dt);
	  return $k['cur'];
}

// ---------- neon fixture ----------
// 'seq' is a list of [state, seconds] steps. Flicker mode walks through it:
// steady ON for a while, then a dropout (single, or a stutter), repeat.
$neon = ['seq' => [], 'state' => true, 'until' => 0, 'out' => true, 'outChanged' => 0];

function neonBuildSeq() {
    $seq = [[true, frand(NEON_GAP_MIN, NEON_GAP_MAX)]];       // steady on
    if (mt_rand(0, 99) < 60) {
        $seq[] = [false, frand(0.15, 0.50)];                  // one dropout
    } else {
        $seq[] = [false, frand(0.10, 0.20)];                  // stutter: off-on-off
        $seq[] = [true,  frand(0.10, 0.25)];
        $seq[] = [false, frand(0.10, 0.30)];
    }
    return $seq;                                              // ends OFF; next seq starts ON
}

function neonDesired($now) {
    global $neon;
    if ($now >= $neon['until']) {
        if (!$neon['seq']) $neon['seq'] = neonBuildSeq();
        list($s, $d) = array_shift($neon['seq']);
        $neon['state'] = $s;
        $neon['until'] = $now + max(NEON_MIN_HOLD, $d);
    }
    return $neon['state'];
}

// Final gatekeeper: no matter who asks (flicker, mode change, off), the output
// can't change state again until NEON_MIN_HOLD has passed.
function neonLimit($want, $now) {
    global $neon;
    if ($want !== $neon['out'] && ($now - $neon['outChanged']) >= NEON_MIN_HOLD) {
        $neon['out'] = $want;
        $neon['outChanged'] = $now;
    }
    return $neon['out'];
}

// puck color: orange_mix 1.0 = full orange, 0.0 = full red. Eases slowly toward target_mix.
$puck_color = ['orange_mix' => 1.0, 'target_mix' => 1.0, 'next_change' => 0];

function flickerFrame($now, $dt) {
    global $candles, $puck_color;
    $f = blankFrame();

    for ($i = 0; $i < LANTERNS; $i++) {
        if (in_array($i + 1, NEON_CHANNELS)) continue;   // neon is handled separately
        $f[$i + 1] = stepCandle($candles[$i], $now, $dt);
    }

    // puck
    $p = stepCandle($candles[LANTERNS], $now, $dt);
    // puck color: mostly orange, now and then a slow slide toward red
    if ($now >= $puck_color['next_change']) {
        $puck_color['target_mix'] = (mt_rand(0, 99) < 60) ? 1.0 : frand(0.0, 0.3);
        $puck_color['next_change'] = $now + frand(5, 12);
    }
    $puck_color['orange_mix'] += ($puck_color['target_mix'] - $puck_color['orange_mix']) * min(1.0, PUCK_FADE_SPEED * $dt);
    $f[PUCK_MASTER]     = $p;
    $f[PUCK_MASTER + 1] = 255;                                           // red always full
    $f[PUCK_MASTER + 2] = PUCK_ORANGE_GREEN * $puck_color['orange_mix']; // green mixed in -> orange
    $f[PUCK_MASTER + 3] = 0;                                             // no blue, no purple
    return $f;
}

function fullFrame() {
    $f = blankFrame();
    for ($i = 1; $i <= LANTERNS; $i++) $f[$i] = 255;
    $f[PUCK_MASTER] = 255; $f[PUCK_MASTER + 1] = 255; $f[PUCK_MASTER + 2] = PUCK_ORANGE_GREEN; // warm orange
    return $f;
}

// ---------- the state machine ----------
$lastMode    = null;
$lastOut     = blankFrame();
$fromFrame   = blankFrame();
$mixT        = 1.0;
$overrideAt  = 0;
$override    = 'auto';
$prev        = microtime(true);
$frameLen    = 1.0 / FPS;
$n = 0; // for debug logging

while (true) {
    $now = microtime(true);
		// delta time.  Time since previous frame.  About 0.033 for 30 frames per second.
    $dt  = $now - $prev;

		if ($dt > 0.1) fprintf(STDERR, "%s FRAME STALL: %.0f ms\n", date('H:i:s'), $dt * 1000);
    $prev = $now;

    // re-check the override file twice a second
    if ($now >= $overrideAt) {
        $override = readOverride();
        $overrideAt = $now + 0.5;
    }

    // decide the effective mode
    if ($override === 'auto') {
        $mode = 'flicker';   // no more breathing: auto just means flicker
    } else {
        $mode = $override;
    }

    // crossfade when the mode changes
    if ($mode !== $lastMode) {
        $fromFrame = $lastOut;
        $mixT = ($lastMode === null) ? 1.0 : 0.0;
        $lastMode = $mode;
    }

    // render this mode's frame
    switch ($mode) {
        case 'full':    $target = fullFrame();               break;
        case 'off':     $target = blankFrame();              break;
        default:        $target = flickerFrame($now, $dt);   break;
    }

    // blend
    if ($mixT < 1.0) {
        $mixT = min(1.0, $mixT + $dt / FADE_SECS);
        $out = blankFrame();
        for ($c = 1; $c <= LAST_CH; $c++) {
            $out[$c] = $fromFrame[$c] + ($target[$c] - $fromFrame[$c]) * $mixT;
        }
    } else {
        $out = $target;
    }

    // neon channels: never blended.
    // everything else = steady on; off = off.
    $want = ($mode !== 'off');   // neon always on (only the 'off' mode turns it off)
    $neonOn = neonLimit($want, $now);
    foreach (NEON_CHANNELS as $nc) $out[$nc] = $neonOn ? 255 : 0;

		// Collect just the lantern levels (skip neon channels, they're always 0 or 255)
		$lantern_levels = [];
		for ($c = 1; $c <= LANTERNS; $c++) {
		    if (!in_array($c, NEON_CHANNELS)) $lantern_levels[] = $out[$c];
		}
		if ($mode !== 'off' && max($lantern_levels) < 5) {
		    fprintf(STDERR, "%s ALL DARK in mode %s\n", date('H:i:s'), $mode);
		}
    sendDmx($ch, $out);
		if (DEBUG && (++$n % DEBUG_EVERY === 0)) {
			$line = [];
			for ($c = 1; $c <= LAST_CH; $c++) $line[] = sprintf('%3d', round(clamp($out[$c])));
	    printf("%-8s %s\n", $mode, implode(' ', $line));
		}
    $lastOut = $out;

    $spent = microtime(true) - $now;
    if ($spent < $frameLen) usleep((int) (($frameLen - $spent) * 1e6));
}
