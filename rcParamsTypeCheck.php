<?php

/**
 * Checks the type of recentchanges.rc_params on all wikis in $wgConf
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\MediaWikiServices;

class RcParamsTypeCheck extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Checks the type of recentchanges.rc_params on all wikis in $wgConf' );
	}

	public function execute() {
		global $wgConf;
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$count = 0;
		foreach ( $wgConf->getLocalDatabases() as $wiki ) {
			$lb = $lbFactory->getMainLB( $wiki );
			$db = $lb->getConnection( DB_MASTER, [], $wiki );
			$field = $db->fieldInfo( 'recentchanges', 'rc_params' );
			if ( $field->type() !== "blob" ) {
				echo $wiki . "\n";
				$count++;
			}
			$lb->reuseConnection( $db );
		}
		$this->output( "$count wikis have recentchanges.rc_params that isn't a blob\n" );
	}
}

$maintClass = RcParamsTypeCheck::class;
require_once RUN_MAINTENANCE_IF_MAIN;
