<?php
require_once __DIR__ . '/WikimediaCommandLine.inc';

$bad = 0;
$good = 0;
$lbFactory = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
foreach ( $wgLocalDatabases as $wiki ) {
	$lb = $lbFactory->getMainLB( $wiki );
	$db = $lb->getConnection( DB_REPLICA, [], $wiki );
	$notEmpty = $db->selectField( 'transcache', '1', [], 'checkTranscacheEmpty.php' );
	if ( $notEmpty ) {
		echo "$wiki\n";
		$bad++;
	} else {
		$good++;
	}
	$lb->reuseConnection( $db );
}
echo "bad = $bad, good = $good\n";
