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
		$this->addOption( 'totalonly', 'Whether to only output the total number of jobs', false, false, false );
	}

	function execute() {
		global $wgConf;
		$outputZero = $this->getOption( 'showzero', false );
		$totalOnly = $this->getOption( 'totalonly', false );
		$total = 0;
		foreach ( $wgConf->getLocalDatabases() as $wiki ) {
			$lb = wfGetLB( $wiki );
			$db = $lb->getConnection( DB_MASTER, array(), $wiki );
			$count = intval( $db->selectField( 'job', 'COUNT(*)', array( 'job_token' => '' ), __METHOD__ ) );
			if ( $outputZero || $count > 0 ) {
				if ( !$totalOnly ) {
					$this->output( "$wiki $count\n" );
				}
				$total += $count;
			}
			$lb->reuseConnection( $db );
		}
		$this->output( "Total $total\n" );
	}
}

$maintClass = 'GetJobQueueLengths';
require_once( DO_MAINTENANCE );
