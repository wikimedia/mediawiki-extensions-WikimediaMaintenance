<?php
require_once __DIR__ . '/WikimediaCommandLineInc.php';

foreach ( $wgLocalDatabases as $db ) {
	print "$db\n";
}
