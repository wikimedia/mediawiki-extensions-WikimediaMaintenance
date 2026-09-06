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
 * Maintenance script to fix drifts in fr_metadata field for old images.
 */
class FixFileRevisionMetadataDrift extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Script to fix drifts in fr_metadata fields' );
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
				'oi_metadata',
				'fr_id',
				'fr_metadata',
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

		// Fix drifts in oldimage vs filerevision
		do {
			$queryBuilder = clone $queryBuilderTemplate;
			$res = $queryBuilder
				->andWhere( $batchCondition )
				->caller( __METHOD__ )
				->fetchResultSet();

			$batchFixed = 0;
			foreach ( $res as $row ) {
				$totalChecked++;

				// Use binary comparison for BLOB fields
				$oiMeta = $row->oi_metadata;
				$frMeta = $row->fr_metadata;
				$metadataDiff = ( strcmp( (string)$oiMeta, (string)$frMeta ) !== 0 );

				if ( $metadataDiff ) {
					$batchFixed++;
					$totalFixed++;

					$this->output(
						"MISMATCH: {$row->oi_name} @ {$row->oi_timestamp} - " .
						"oi_metadata length=" . strlen( (string)$oiMeta ) . ", " .
						"fr_metadata length=" . strlen( (string)$frMeta )
					);

					if ( !$dryRun ) {
						$dbw->newUpdateQueryBuilder()
							->update( 'filerevision' )
							->set( [ 'fr_metadata' => $row->oi_metadata ] )
							->where( [ 'fr_id' => $row->fr_id ] )
							->caller( __METHOD__ )->execute();
						$this->output(
							" -> FIXED filerevision to match oldimage " .
							"(affected rows: {$dbw->affectedRows()})\n"
						);
					} else {
						$this->output( " -> WOULD FIX filerevision to match oldimage\n" );
					}
				} elseif ( $verbose ) {
					$this->output(
						"OK: {$row->oi_name} @ {$row->oi_timestamp} - " .
						"oi_metadata length=" . strlen( (string)$oiMeta ) . ", " .
						"fr_metadata length=" . strlen( (string)$frMeta ) . "\n"
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

		// Fix drifts in image vs filerevision (current revisions)
		$this->output( "\nChecking current image revisions...\n" );

		$imageQueryBuilderTemplate = $dbw->newSelectQueryBuilder()
			->select( [
				'img_name',
				'img_metadata',
				'fr_id',
				'fr_metadata',
				'file_id'
			] )
			->from( 'image' )
			->join(
				'file',
				'f',
				'f.file_name = img_name'
			)
			->join(
				'filerevision',
				'fr',
				[ 'fr.fr_file = f.file_id', 'fr.fr_timestamp = img_timestamp', 'fr.fr_sha1 = img_sha1' ]
			);

		if ( $end !== false ) {
			$imageQueryBuilderTemplate->andWhere( $dbw->expr( 'img_name', '<=', $end ) );
		}

		$imageQueryBuilderTemplate
			->orderBy( 'img_name', SelectQueryBuilder::SORT_ASC )
			->limit( $batchSize );

		$imageBatchCondition = [];
		if ( $start !== false ) {
			$imageBatchCondition[] = $dbw->expr( 'img_name', '>=', $start );
		}

		$imagesProcessed = 0;
		do {
			$imageQueryBuilder = clone $imageQueryBuilderTemplate;
			$imageRes = $imageQueryBuilder
				->andWhere( $imageBatchCondition )
				->caller( __METHOD__ )
				->fetchResultSet();

			$batchFixed = 0;
			foreach ( $imageRes as $row ) {
				$totalChecked++;

				// Use binary comparison for BLOB fields
				$imgMeta = $row->img_metadata;
				$frMeta = $row->fr_metadata;
				$metadataDiff = ( strcmp( (string)$imgMeta, (string)$frMeta ) !== 0 );

				if ( $metadataDiff ) {
					$batchFixed++;
					$totalFixed++;

					$this->output(
						"MISMATCH (current): {$row->img_name} - " .
						"img_metadata length=" . strlen( (string)$imgMeta ) . ", " .
						"fr_metadata length=" . strlen( (string)$frMeta )
					);

					if ( !$dryRun ) {
						$dbw->newUpdateQueryBuilder()
							->update( 'filerevision' )
							->set( [ 'fr_metadata' => $row->img_metadata ] )
							->where( [ 'fr_id' => $row->fr_id ] )
							->caller( __METHOD__ )->execute();
						$this->output(
							" -> FIXED filerevision to match image " .
							"(affected rows: {$dbw->affectedRows()})\n"
						);
					} else {
						$this->output( " -> WOULD FIX filerevision to match image\n" );
					}
				} elseif ( $verbose ) {
					$this->output(
						"OK (current): {$row->img_name} - " .
						"img_metadata length=" . strlen( (string)$imgMeta ) . ", " .
						"fr_metadata length=" . strlen( (string)$frMeta ) . "\n"
					);
				}
			}

			if ( $imageRes->numRows() > 0 ) {
				$lastRow = $imageRes->current();
				$imageRes->seek( $imageRes->numRows() - 1 );
				$lastRow = $imageRes->current();
				$imageBatchCondition = [ $dbw->expr( 'img_name', '>', $lastRow->img_name ) ];
				$imagesProcessed++;
			}

			if ( $batchFixed > 0 ) {
				$this->output( "Batch completed: {$batchFixed} drifts fixed in this batch.\n" );
			}

			$this->waitForReplication();
			if ( $sleep ) {
				sleep( $sleep );
			}

		} while ( $imageRes->numRows() === $batchSize );

		$this->output( "\nSummary:\n" );
		$this->output( "- Total oldimage revisions checked: {$totalChecked}\n" );
		$this->output( "- Total drifts found: {$totalFixed}\n" );
		$this->output( "- Oldimage revisions processed: {$filesProcessed}\n" );
		$this->output( "- Current image revisions processed: {$imagesProcessed}\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = FixFileRevisionMetadataDrift::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
