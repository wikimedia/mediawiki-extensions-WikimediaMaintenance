<?php

use Wikimedia\Rdbms\IMaintainableDatabase;

require_once __DIR__ . '/../WikimediaMaintenance.php';

class TestRctComplete extends Maintenance {
	public function __construct() {
		// $this->addDescription('' );
		parent::__construct();
	}

	public function execute() {
		global $wgLocalDatabases;
		$bad = 0;
		$good = 0;
		$lbFactory = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		foreach ( $wgLocalDatabases as $wiki ) {
			$lb = $lbFactory->getMainLB( $wiki );
			$db = $lb->getConnection( DB_REPLICA, [], $wiki );
			'@phan-var IMaintainableDatabase $db';
			if ( $db->tableExists( 'blob_tracking', __METHOD__ ) ) {
				$notDone = $db->selectField(
					'blob_tracking',
					'1',
					[ 'bt_moved' => 0 ],
					__METHOD__
				);
				if ( $notDone ) {
					$bad++;
					echo "$wiki\n";
				} else {
					$good++;
				}
			}
			$lb->reuseConnection( $db );
		}
		$this->output( "$bad wiki(s) incomplete\n" );
		$this->output( "$good wiki(s) complete\n" );
	}

}

$maintClass = TestRctComplete::class;
require_once RUN_MAINTENANCE_IF_MAIN;
