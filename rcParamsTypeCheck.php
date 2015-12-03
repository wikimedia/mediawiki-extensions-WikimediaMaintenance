<?php

/**
 * Get the length of the job queue on all wikis in $wgConf
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

class GetJobQueueLengths extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = '';
	}

	function execute() {
		global $wgConf;
		$count = 0;
		foreach ( $wgConf->getLocalDatabases() as $wiki ) {
			$lb = wfGetLB( $wiki );
			$db = $lb->getConnection( DB_MASTER, array(), $wiki );
			$field = $db->fieldInfo( 'recentchanges', 'rc_params' );
			if ( $field->type() !== "blob" ) {
				echo $wiki . "\n";
				$count++;
			}
			$lb->reuseConnection( $db );
		}
		$this->output( $count . "\n" );
	}
}

$maintClass = 'GetJobQueueLengths';
require_once( DO_MAINTENANCE );
