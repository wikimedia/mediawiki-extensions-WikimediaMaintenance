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
		$outputZero = $this->getOption( 'showzero', false );
		$totalOnly = $this->getOption( 'totalonly', false );

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
			if ( $outputZero || $count > 0 ) {
				if ( !$totalOnly ) {
					$this->output( "$wiki $count\n" );
				}
				$total += $count;
			}
		}
		$this->output( "Total $total\n" );
	}
}

$maintClass = 'GetJobQueueLengths';
require_once( DO_MAINTENANCE );
