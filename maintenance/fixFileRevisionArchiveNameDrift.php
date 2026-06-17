<?php
/**
 * @license GPL-2.0-or-later
 * @file
 */

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Maintenance script to fix drifts in fr_archive_name field for old images.
 */
class FixFileRevisionArchiveNameDrift extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Script to fix drifts in fr_archive_name field for old images' );
		$this->setBatchSize( 1000 );
		$this->addOption( 'start', 'Name of file to start with', false, true );
		$this->addOption( 'end', 'Name of file to end with', false, true );
		$this->addOption( 'dry-run', 'Only show what would be changed without making updates' );
		$this->addOption(
			'sleep',
			'Time to sleep between each batch (in seconds). Default: 0',
			false,
			true
		);
	}

	public function execute() {
		$verbose = $this->hasOption( 'verbose' );
		$dryRun = $this->hasOption( 'dry-run' );
		$start = $this->getOption( 'start', false );
		$end = $this->getOption( 'end', false );
		$sleep = (int)$this->getOption( 'sleep', 0 );

		$dbw = $this->getPrimaryDB();
		$batchSize = $this->getBatchSize();

		$queryBuilderTemplate = $dbw->newSelectQueryBuilder()
			->select( [
				'oi_name',
				'oi_timestamp',
				'oi_sha1',
				'oi_archive_name',
				'fr_id',
				'fr_archive_name',
				'file_id'
			] )
			->from( 'oldimage' )
			->join(
				'file',
				'f',
				'f.file_name = oi_name'
			)
			->join(
				'filerevision',
				'fr',
				[ 'fr.fr_file = f.file_id', 'fr.fr_timestamp = oi_timestamp', 'fr.fr_sha1 = oi_sha1' ]
			);

		if ( $end !== false ) {
			$queryBuilderTemplate->andWhere( $dbw->expr( 'oi_name', '<=', $end ) );
		}

		$queryBuilderTemplate
			->orderBy( 'oi_name', SelectQueryBuilder::SORT_ASC )
			->orderBy( 'oi_timestamp', SelectQueryBuilder::SORT_ASC )
			->limit( $batchSize );

		$batchCondition = [];
		if ( $start !== false ) {
			$batchCondition[] = $dbw->expr( 'oi_name', '>=', $start );
		}

		$totalChecked = 0;
		$totalFixed = 0;
		$filesProcessed = 0;

		do {
			$queryBuilder = clone $queryBuilderTemplate;
			$res = $queryBuilder
				->andWhere( $batchCondition )
				->caller( __METHOD__ )
				->fetchResultSet();

			$batchFixed = 0;
			foreach ( $res as $row ) {
				$totalChecked++;

				if ( $row->oi_archive_name != $row->fr_archive_name ) {
					$batchFixed++;
					$totalFixed++;

					$this->output(
						"MISMATCH: {$row->oi_name} @ {$row->oi_timestamp} - " .
						"oi_archive_name={$row->oi_archive_name}, fr_archive_name={$row->fr_archive_name}"
					);

					if ( !$dryRun ) {
						$dbw->newUpdateQueryBuilder()
							->update( 'filerevision' )
							->set( [ 'fr_archive_name' => $row->oi_archive_name ] )
							->where( [ 'fr_id' => $row->fr_id ] )
							->caller( __METHOD__ )->execute();

						$this->output( " -> FIXED to {$row->oi_archive_name}\n" );
					} else {
						$this->output( " -> WOULD FIX to {$row->oi_archive_name}\n" );
					}
				} elseif ( $verbose ) {
					$this->output(
						"OK: {$row->oi_name} @ {$row->oi_timestamp} - " .
						"oi_archive_name={$row->oi_archive_name}, fr_archive_name={$row->fr_archive_name}\n"
					);
				}
			}

			if ( $res->numRows() > 0 ) {
				$lastRow = $res->current();
				$res->seek( $res->numRows() - 1 );
				$lastRow = $res->current();
				$batchCondition = [ $dbw->expr( 'oi_name', '>', $lastRow->oi_name ) ];
				$filesProcessed++;
			}

			if ( $batchFixed > 0 ) {
				$this->output( "Batch completed: {$batchFixed} drifts fixed in this batch.\n" );
			}

			$this->waitForReplication();
			if ( $sleep ) {
				sleep( $sleep );
			}

		} while ( $res->numRows() === $batchSize );

		$this->output( "\nSummary:\n" );
		$this->output( "- Total oldimage revisions checked: {$totalChecked}\n" );
		$this->output( "- Total drifts found: {$totalFixed}\n" );
		$this->output( "- Files processed: {$filesProcessed}\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = FixFileRevisionArchiveNameDrift::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
