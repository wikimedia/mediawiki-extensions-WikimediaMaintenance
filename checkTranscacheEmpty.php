<?php
require_once __DIR__ .'/WikimediaCommandLine.inc';

$bad = 0;
$good = 0;
foreach ( $wgLocalDatabases as $wiki ) {
	$lb = wfGetLB( $wiki );
	$db = $lb->getConnection( DB_SLAVE, array(), $wiki );
	$notEmpty = $db->selectField( 'transcache', '1', false, 'checkTranscacheEmpty.php' );
	if ( $notEmpty ) {
		echo "$wiki\n";
		$bad++;
	} else {
		$good++;
	}
	$lb->reuseConnection( $db );
}
echo "bad = $bad, good = $good\n";
