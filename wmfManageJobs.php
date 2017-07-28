<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * WMF-specific version of mediawiki/maintenance/manageJobs.php.
 *
 * Main use case is to be able to perform actions on the job queue even for
 * Wiki IDs that are no longer recognised by MediaWiki.
 * It communicates directly with the job queue using the Job Config of
 * the wiki the script was run from, under the assumption that all wikis
 * share the same job queue configuration. (Or at least the target wiki,
 * and the used wiki).
 *
 * For regular wikis that still exist and operate normally, prefer
 * using MediaWiki core's manageJobs.php instead.
 *
 * Show current queues (like showJobs.php):
 *
 *  $ mwscript extensions/WikimediaMaintenance/wmfManageJobs.php --wiki=aawiki --target=testwiki
 *
 * Delete current queues (like manageJobs.php --delete):
 *
 *  $ mwscript extensions/WikimediaMaintenance/wmfManageJobs.php --wiki=aawiki --target=testwiki --delete
 */
class WmfManageJobs extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Manage job queue for a particular wiki-id' );
		$this->addOption( 'target', 'Which Wiki-ID to operate on', true, true );
		$this->addOption( 'delete', 'Delete all queues for this wiki ID', false, false );
	}

	public function execute() {
		$target = $this->getOption( 'target' );
		$delete = $this->hasOption( 'delete' );

		$group = JobQueueGroup::singleton( $target );

		/**
		 * Get a list of job types for this wiki
		 * - Can't use $group->getQueueTypes() because that it uses SiteConfig
		 *   to read 'wgJobClasses' from the target wiki, which may not exist
		 * - Can't use `$this->getConfig()->get( 'JobClasses' )` because which
		 *   jobs are recognised varies from one wiki to another (which extensions
		 *   are installed etc, there is no "catch-all" registry)
		 * - Workaround it by levering JobQueueAggregator, which tracks jobs
		 *   in a structured globally keyed by job type first.
		 */
		$aggregator = JobQueueAggregator::singleton();
		$types = array_keys( $aggregator->getAllReadyWikiQueues() );

		$deleteTypes = [];
		$total = 0;

		// Show current job queue status
		// (Based on mediawiki/maintenance/showJobs.php)
		foreach ( $types as $type ) {
			$queue = $group->get( $type );
			$pending = $queue->getSize();
			$delayed = $queue->getDelayedCount();
			$claimed = $queue->getAcquiredCount();
			$abandoned = $queue->getAbandonedCount();
			$subtotal = $pending + $delayed + $claimed + $abandoned;
			if ( $subtotal ) {
				$total += $subtotal;
				$active = max( 0, $claimed - $abandoned );
				$this->output(
					"{$type}: $pending queued; " .
					"$claimed claimed ($active active, $abandoned abandoned); " .
					"$delayed delayed\n"
				);
			} else {
				$this->output(
					"{$type}: 0 queued; (empty or doesn't exist)\n"
				);
			}
			// Always attempt deletion, even if queue doesn't exist (lists
			// not existing are indistinguishable from empty lists because
			// Redis::lSize returns 0 for not found)
			$deleteTypes[$type] = $queue;
		}

		if ( !$deleteTypes ) {
			$this->output( "\nNo queues found for $target.\n" );
			return;
		}

		if ( !$delete ) {
			$this->output( "\nRun the script again with --delete to delete these queues.\n" );
			return;
		}

		$count = count( $deleteTypes );
		$this->output( "\n\nThe script will now try to delete $total job(s), " .
			"from $count different queue(s), for this wiki: $target\n" );
		$this->output( 'Abort with control-C in the next five seconds...' );
		wfCountDown( 5 );

		foreach ( $deleteTypes as $type => $queue ) {
			$this->output( "$type: deleting...\n" );
			$queue->delete();
		}
		$this->output( "Done!\n" );
	}
}

$maintClass = WmfManageJobs::class;
require_once RUN_MAINTENANCE_IF_MAIN;
