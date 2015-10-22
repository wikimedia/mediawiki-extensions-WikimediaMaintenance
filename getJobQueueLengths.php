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
		$this->addOption( 'grouponly', "Show a per-wiki/per-type count map in JSON" );
		$this->addOption( 'report', "Report the total count of job queue items to StatsD" );
	}

	function execute() {
		if ( $this->hasOption( 'grouponly' ) && $this->hasOption( 'report' ) ) {
			$this->error( "You cannot specify both '--report' and '--grouponly'.\n" );
			exit( 1 );
		}

		$totalOnly = $this->hasOption( 'totalonly' );

		$pendingDBs = JobQueueAggregator::singleton()->getAllReadyWikiQueues();
		$sizeByWiki = array(); // (wiki => type => count) map
		foreach ( $pendingDBs as $type => $wikis ) {
			foreach ( $wikis as $wiki ) {
				$sizeByWiki[$wiki][$type] =
					JobQueueGroup::singleton( $wiki )->get( $type )->getSize();
			}
		}

		if ( $this->hasOption( 'grouponly' ) ) {
			$this->output( FormatJSON::encode( $sizeByWiki, true ) . "\n" );
		} else {
			$total = 0;
			foreach ( $sizeByWiki as $wiki => $counts ) {
				$count = array_sum( $counts );
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

		if ( $this->hasOption( 'report' ) ) {
			$stats = RequestContext::getMain()->getStats();
			$stats->gauge( 'jobqueue.size', $total );
		}
	}
}

$maintClass = 'GetJobQueueLengths';
require_once( DO_MAINTENANCE );
