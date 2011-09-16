<?php

include('commandLine.inc');

$i=1000;

$start = microtime(true);
wfMsg("pagetitle");
$time = microtime(true) - $start;
print "Init time: $time\n";

$start=microtime(true);
while ($i--) { 
 wfMsg("pagetitle");
}

$time = microtime(true) - $start;
print "Time: $time\n";
