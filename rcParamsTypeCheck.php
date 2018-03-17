<?php

/**
 * Checks the type of recentchanges.rc_params on all wikis in $wgConf
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

class RCParamsTypeCheck extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = 'Checks the type of recentchanges.rc_params on all wikis in $wgConf';
	}

	function execute() {
		global $wgConf;
		$count = 0;
		foreach ( $wgConf->getLocalDatabases() as $wiki ) {
			$lb = wfGetLB( $wiki );
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

$maintClass = 'RCParamsTypeCheck';
require_once RUN_MAINTENANCE_IF_MAIN;
