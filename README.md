# Home Lighting

Simple scripts to automate my home's lighting, powered by a Raspberry Pi and Open Lighting Architecture ("OLA")

http://opendmx.net/index.php/Open_Lighting_Architecture

I'm loving the DMX King USB DMX dongle, it works beautifully on my RPi, OSX, Windows, and FreeBSD boxes

https://shop.dmxking.com/ultraDMX-Micro_p_15.html

## sunset.php

At sundown, turn on home exterior DMX lighting
At midnight, dim back lighting
At sunrise, turn lighting off

Run from crontab:
 `* * * * * ~/lighting/sunset.php > /dev/null 2>&1`

DMX Dimmer pack on channels 1 and 2

## Halloween 2015

![Home's lighting example photo](http://presence.irev.net/photos/d/18224-2/Halloween+2015+Walkway+Cool.JPG)
![Home's lighting example photo](http://presence.irev.net/photos/d/18227-2/Halloween+2015+Walkway+Warm.JPG)

Photos online at: http://presence.irev.net/v/RandomStuff/Halloween2015/

Two themes: 

 * Orange/green "nice" mode with seemingly-natural flickering lanterns and sparkling wall lighting 
 * Blue/Purple "scary" mode where fixutres are dimmed, flickering exaggerated

Run from the command line:  

 * `halloween nice`
 * `halloween scary`

Or chained into a sequence on the command line:

 * `$ (halloween scary; halloween nice; halloween nice; halloween scary; halloween nice; halloween nice; halloween nice; halloween scary; halloween nice;)`

## ToDo

 * Halloween would be rad if an audio cue played when transitioning between modes: thunder clap or howl or laughter or chimes
 * Halloween modes automated by GPIO pin trigger instead of command line
 * Halloween modes (and audio cues?) manual control via web browser

