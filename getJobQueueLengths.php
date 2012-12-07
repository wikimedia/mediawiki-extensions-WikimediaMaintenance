<?php

/**
 * Get the length of the job queue on all wikis in $wgConf
 */

require_once( __DIR__ .'/WikimediaMaintenance.php' );

class GetJobQueueLengths extends WikimediaMaintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = 'Get the length of the job queue on all wikis in $wgConf';
		$this->addOption( 'showzero', 'Whether to output wikis with 0 jobs', false, false, false );
	}

	function execute() {
		global $wgConf;
		$outputZero = $this->getOption( 'showzero', false );
		$total = 0;
		foreach ( $wgConf->getLocalDatabases() as $wiki ) {
			$lb = wfGetLB( $wiki );
			$db = $lb->getConnection( DB_MASTER, array(), $wiki );
			$count = intval( $db->selectField( 'job', 'COUNT(*)', '', __METHOD__ ) );
			if ( $outputZero || $count > 0 ) {
				$this->output( "$wiki $count\n" );
				$total += $count;
			}
			$lb->reuseConnection( $db );
		}
		$this->output( "Total $total" );
	}
}

$maintClass = 'GetJobQueueLengths';
require_once( DO_MAINTENANCE );
