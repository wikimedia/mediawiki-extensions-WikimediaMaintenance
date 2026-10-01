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
 * Maintenance script to deduplicate oldimage and filerevision tables.
 */
class DeduplicateFileRevisions extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Script to deduplicate oldimage and filerevision tables' );
		$this->setBatchSize( 1000 );
		$this->addOption( 'dry-run', 'Only show what would be changed without making updates' );
	}

	public function execute() {
		$dryRun = $this->hasOption( 'dry-run' );
		$dbw = $this->getPrimaryDB();
		$batchSize = $this->getBatchSize();

		$this->output( "=== Deduplicating oldimage table ===\n" );
		$this->deduplicateOldimage( $dbw, $batchSize, $dryRun );

		$this->output( "\n=== Deduplicating filerevision table ===\n" );
		$this->deduplicateFilerevision( $dbw, $batchSize, $dryRun );

		$this->output( "\nDeduplication complete.\n" );
	}

	/**
	 * @param \Wikimedia\Rdbms\IDatabase $dbw Database connection
	 * @param int $batchSize Number of duplicate groups to process per batch
	 * @param bool $dryRun If true, only show what would be deleted
	 */
	private function deduplicateOldimage( $dbw, $batchSize, $dryRun ) {
		$totalDuplicates = 0;
		$totalDeleted = 0;
		$lastName = null;

		do {
			$query = $dbw->newSelectQueryBuilder()
				->select( [ 'oi_name', 'oi_timestamp' ] )
				->from( 'oldimage' )
				->groupBy( [ 'oi_name', 'oi_timestamp' ] )
				->having( 'COUNT(*) > 1' )
				->orderBy( 'oi_name', SelectQueryBuilder::SORT_ASC )
				->orderBy( 'oi_timestamp', SelectQueryBuilder::SORT_ASC )
				->limit( $batchSize );

			if ( $lastName !== null ) {
				$query->andWhere( $dbw->expr( 'oi_name', '>', $lastName ) );
			}

			$res = $query->caller( __METHOD__ )->fetchResultSet();
			$lastRow = null;

			foreach ( $res as $row ) {
				$lastRow = $row;
				$totalDuplicates++;

				$rows = $dbw->newSelectQueryBuilder()
					->select( '*' )
					->from( 'oldimage' )
					->where( [
						'oi_name' => $row->oi_name,
						'oi_timestamp' => $row->oi_timestamp
					] )
					->caller( __METHOD__ )
					->fetchResultSet();

				$keepRow = null;
				$deleteRows = [];

				foreach ( $rows as $dupRow ) {
					$hasMetadata = !empty( $dupRow->oi_metadata );

					if ( $hasMetadata && $keepRow === null ) {
						$keepRow = $dupRow;
					} elseif ( $hasMetadata && $keepRow !== null && empty( $keepRow->oi_metadata ) ) {
						$deleteRows[] = $keepRow;
						$keepRow = $dupRow;
					} elseif ( $keepRow === null ) {
						$keepRow = $dupRow;
					} else {
						$deleteRows[] = $dupRow;
					}
				}

				foreach ( $deleteRows as $delRow ) {
					$this->warnAboutMismatches( $keepRow, $delRow, 'oldimage' );
				}

				foreach ( $deleteRows as $delRow ) {
					$this->output(
						"DELETE oldimage: {$delRow->oi_name} @ {$delRow->oi_timestamp} " .
						"(archive_name={$delRow->oi_archive_name})\n"
					);

					if ( !$dryRun ) {
						$dbw->newDeleteQueryBuilder()
							->deleteFrom( 'oldimage' )
							->where( [ 'oi_archive_name' => $delRow->oi_archive_name ] )
							->caller( __METHOD__ )
							->execute();
					} else {
						$this->output( " -> WOULD DELETE\n" );
					}

					$totalDeleted++;
				}

				$this->waitForReplication();
			}

			if ( $lastRow !== null ) {
				$lastName = $lastRow->oi_name;
			}

		} while ( $res->numRows() === $batchSize );

		$this->output( "Oldimage: Found {$totalDuplicates} duplicate groups, " .
			( $dryRun ? 'would delete' : 'deleted' ) . " {$totalDeleted} rows.\n" );
	}

	/**
	 * @param \Wikimedia\Rdbms\IDatabase $dbw Database connection
	 * @param int $batchSize Number of duplicate groups to process per batch
	 * @param bool $dryRun If true, only show what would be deleted
	 */
	private function deduplicateFilerevision( $dbw, $batchSize, $dryRun ) {
		$totalDuplicates = 0;
		$totalDeleted = 0;
		$lastFileId = null;

		do {
			$query = $dbw->newSelectQueryBuilder()
				->select( [ 'fr_file', 'fr_timestamp' ] )
				->from( 'filerevision', 'fr' )
				->join( 'file', 'f', 'f.file_id = fr.fr_file' )
				->groupBy( [ 'fr_file', 'fr_timestamp' ] )
				->having( 'COUNT(*) > 1' )
				->orderBy( 'file_name', SelectQueryBuilder::SORT_ASC )
				->orderBy( 'fr_timestamp', SelectQueryBuilder::SORT_ASC )
				->limit( $batchSize );

			if ( $lastFileId !== null ) {
				$query->andWhere( $dbw->expr( 'fr_file', '>', $lastFileId ) );
			}

			$res = $query->caller( __METHOD__ )->fetchResultSet();
			$lastRow = null;

			foreach ( $res as $row ) {
				$lastRow = $row;
				$totalDuplicates++;

				$rows = $dbw->newSelectQueryBuilder()
					->select( '*' )
					->from( 'filerevision' )
					->where( [
						'fr_file' => $row->fr_file,
						'fr_timestamp' => $row->fr_timestamp
					] )
					->caller( __METHOD__ )
					->fetchResultSet();

				$keepRow = null;
				$deleteRows = [];

				foreach ( $rows as $dupRow ) {
					$hasMetadata = !empty( $dupRow->fr_metadata );

					if ( $hasMetadata && $keepRow === null ) {
						$keepRow = $dupRow;
					} elseif ( $hasMetadata && $keepRow !== null && empty( $keepRow->fr_metadata ) ) {
						$deleteRows[] = $keepRow;
						$keepRow = $dupRow;
					} elseif ( $keepRow === null ) {
						$keepRow = $dupRow;
					} else {
						$deleteRows[] = $dupRow;
					}
				}

				foreach ( $deleteRows as $delRow ) {
					$this->warnAboutMismatches( $keepRow, $delRow, 'filerevision' );
				}

				foreach ( $deleteRows as $delRow ) {
					$this->output(
						"DELETE filerevision: ID {$delRow->fr_id} @ {$delRow->fr_timestamp} " .
						"(archive_name={$delRow->fr_archive_name})\n"
					);

					if ( !$dryRun ) {
						$dbw->newDeleteQueryBuilder()
							->deleteFrom( 'filerevision' )
							->where( [ 'fr_id' => $delRow->fr_id ] )
							->caller( __METHOD__ )
							->execute();
					} else {
						$this->output( " -> WOULD DELETE\n" );
					}

					$totalDeleted++;
				}

				$this->waitForReplication();
			}

			if ( $lastRow !== null ) {
				$lastFileId = $lastRow->fr_file;
			}

		} while ( $res->numRows() === $batchSize );

		$this->output( "Filerevision: Found {$totalDuplicates} duplicate groups, " .
			( $dryRun ? 'would delete' : 'deleted' ) . " {$totalDeleted} rows.\n" );
	}

	/**
	 * Warn about field mismatches between kept and deleted rows
	 * @param stdClass $keepRow The row being kept
	 * @param stdClass $delRow The row being deleted
	 * @param string $table Table name ('oldimage' or 'filerevision')
	 */
	private function warnAboutMismatches( $keepRow, $delRow, $table ) {
		$fieldsToCheck = [
			'oldimage' => [ 'oi_size', 'oi_width', 'oi_height', 'oi_bits', 'oi_sha1' ],
			'filerevision' => [ 'fr_size', 'fr_width', 'fr_height', 'fr_bits', 'fr_sha1' ]
		];

		$mismatches = [];
		foreach ( $fieldsToCheck[$table] as $field ) {
			if ( $keepRow->$field != $delRow->$field ) {
				$mismatches[] = "$field (keep: {$keepRow->$field}, delete: {$delRow->$field})";
			}
		}

		if ( $mismatches ) {
			$this->output(
				"WARNING: Mismatch in $table fields: " . implode( ', ', $mismatches ) . "\n"
			);
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = DeduplicateFileRevisions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
