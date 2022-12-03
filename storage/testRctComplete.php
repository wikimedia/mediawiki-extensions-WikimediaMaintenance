<?php

use Wikimedia\Rdbms\IMaintainableDatabase;

require_once __DIR__ . '/../WikimediaCommandLineInc.php';

$bad = 0;
$good = 0;
$lbFactory = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
foreach ( $wgLocalDatabases as $wiki ) {
	$lb = $lbFactory->getMainLB( $wiki );
	$db = $lb->getConnection( DB_REPLICA, [], $wiki );
	'@phan-var IMaintainableDatabase $db';
	if ( $db->tableExists( 'blob_tracking', 'testRctComplete' ) ) {
		$notDone = $db->selectField( 'blob_tracking', '1',
			[ 'bt_moved' => 0 ], 'testRctComplete' );
		if ( $notDone ) {
			$bad++;
			echo "$wiki\n";
		} else {
			$good++;
		}
	}
	$lb->reuseConnection( $db );
}
echo "$bad wiki(s) incomplete\n";
echo "$good wiki(s) complete\n";
