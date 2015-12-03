<?php

require_once __DIR__ .'/WikimediaCommandLine.inc';

$i = 1000;

$start = microtime( true );
wfMessage( "pagetitle" )->text();
$time = microtime( true ) - $start;
print "Init time: $time\n";

$start = microtime( true );
while ( $i-- ) {
	wfMessage( "pagetitle" )->text();
}

$time = microtime( true ) - $start;
print "Time: $time\n";
