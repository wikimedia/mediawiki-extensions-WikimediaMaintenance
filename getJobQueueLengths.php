<?php

/**
 * Get the length of the job queue on all wikis in $wgConf
 */

require_once( __DIR__ .'/WikimediaMaintenance.php' );

class GetJobQueueLengths extends WikimediaMaintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = 'Get the length of the job queue on all wikis in $wgConf';
		$this->addOption( 'totalonly', 'Whether to only output the total number of jobs' );
		$this->addOption( 'nototal', "Don't print the total number of jobs" );
	}

	function execute() {
		$totalOnly = $this->hasOption( 'totalonly' );

		$total = 0;
		$pendingDBs = JobQueueAggregator::singleton()->getAllReadyWikiQueues();
		$sizeByWiki = array();
		foreach ( $pendingDBs as $type => $wikis ) {
			foreach ( $wikis as $wiki ) {
				$sizeByWiki[$wiki] = isset( $sizeByWiki[$wiki] ) ? $sizeByWiki[$wiki] : 0;
				$sizeByWiki[$wiki] += JobQueueGroup::singleton( $wiki )->get( $type )->getSize();
			}
		}
		foreach ( $sizeByWiki as $wiki => $count ) {
			if ( $count > 0 ) {
				if ( !$totalOnly ) {
					$this->output( "$wiki $count\n" );
				}
				$total += $count;
			}
		}
		if ( !$this->hasOption( 'nototal' ) ) {
			$this->output( "Total $total\n" );
		}
	}
}

$maintClass = 'GetJobQueueLengths';
require_once( DO_MAINTENANCE );
