<?php
/**
 * Compare the file tables with the image and oldimage tables.
 *
 * @license GPL-2.0-or-later
 * @file
 * @ingroup Maintenance
 */

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * Maintenance script to find differences between the image/oldimage tables
 * and the file/filerevision tables during the file schema migration.
 *
 * Run this before switching $wgFileSchemaMigrationStage to read the new schema.
 * Files changed while the script runs can show up as false positives, so run
 * it again for those.
 *
 * @ingroup Maintenance
 */
class CheckFileTables extends Maintenance {

	private int $problems = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Find differences between the image/oldimage and file/filerevision tables. ' .
			'Only works while both schemas are written.'
		);
		$this->addOption( 'start', 'Name of the file to start with', false, true );
		$this->setBatchSize( 500 );
	}

	/** @inheritDoc */
	public function execute() {
		$dbr = $this->getReplicaDB();
		$start = (string)$this->getOption( 'start', '' );
		$this->checkImageRows( $dbr, $start );
		$this->checkFileRows( $dbr, $start );
		$this->checkOrphanedRevisions( $dbr );

		$this->output( "Found {$this->problems} problem(s).\n" );
		return $this->problems === 0;
	}

	/**
	 * Every file in the image table needs a live file row whose latest revision matches,
	 * and a filerevision row for every old version in oldimage.
	 */
	private function checkImageRows( IReadableDatabase $dbr, string $start ): void {
		$this->output( "Checking image and oldimage rows...\n" );
		$after = null;
		do {
			$queryBuilder = $dbr->newSelectQueryBuilder()
				->select( [ 'img_name', 'img_timestamp', 'img_sha1' ] )
				->from( 'image' )
				->orderBy( 'img_name', SelectQueryBuilder::SORT_ASC )
				->limit( $this->getBatchSize() );
			if ( $after !== null ) {
				$queryBuilder->where( $dbr->expr( 'img_name', '>', $after ) );
			} elseif ( $start !== '' ) {
				$queryBuilder->where( $dbr->expr( 'img_name', '>=', $start ) );
			}
			$imageRows = [];
			foreach ( $queryBuilder->caller( __METHOD__ )->fetchResultSet() as $row ) {
				$imageRows[$row->img_name] = $row;
			}
			if ( !$imageRows ) {
				break;
			}
			$names = array_map( 'strval', array_keys( $imageRows ) );
			$after = end( $names );

			$fileRows = [];
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'file_name', 'file_deleted', 'file_latest', 'fr_timestamp', 'fr_sha1' ] )
				->from( 'file' )
				->leftJoin( 'filerevision', null, 'fr_id = file_latest' )
				->where( [ 'file_name' => $names ] )
				->caller( __METHOD__ )->fetchResultSet();
			foreach ( $res as $row ) {
				$fileRows[$row->file_name] = $row;
			}

			$oldVersions = [];
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'oi_name', 'oi_timestamp', 'oi_sha1' ] )
				->from( 'oldimage' )
				->where( [ 'oi_name' => $names ] )
				->caller( __METHOD__ )->fetchResultSet();
			foreach ( $res as $row ) {
				$oldVersions[$row->oi_name][$this->revisionKey( $row->oi_timestamp, $row->oi_sha1 )] = true;
			}

			$oldRevisions = [];
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'file_name', 'fr_timestamp', 'fr_sha1' ] )
				->from( 'filerevision' )
				->join( 'file', null, 'fr_file = file_id' )
				->where( [ 'file_name' => $names ] )
				->andWhere( 'fr_id != file_latest' )
				->caller( __METHOD__ )->fetchResultSet();
			foreach ( $res as $row ) {
				$oldRevisions[$row->file_name][$this->revisionKey( $row->fr_timestamp, $row->fr_sha1 )] = true;
			}

			foreach ( $imageRows as $name => $imageRow ) {
				$this->checkImageRow(
					(string)$name,
					$imageRow,
					$fileRows[$name] ?? null,
					$oldVersions[$name] ?? [],
					$oldRevisions[$name] ?? []
				);
			}
		} while ( count( $imageRows ) === $this->getBatchSize() );
	}

	private function checkImageRow(
		string $name, stdClass $imageRow, ?stdClass $fileRow, array $oldVersions, array $oldRevisions
	): void {
		if ( !$fileRow ) {
			$this->report( $name, 'not migrated, there is no file row' );
			return;
		}
		if ( (int)$fileRow->file_deleted !== 0 ) {
			$this->report( $name, 'marked as deleted in the file table, but exists in the image table' );
		}
		if ( $fileRow->fr_timestamp === null ) {
			$this->report( $name, "file_latest ({$fileRow->file_latest}) doesn't point to a filerevision row" );
		} elseif (
			$this->revisionKey( $fileRow->fr_timestamp, $fileRow->fr_sha1 ) !==
			$this->revisionKey( $imageRow->img_timestamp, $imageRow->img_sha1 )
		) {
			$this->report( $name, 'the latest filerevision row differs from the image row' );
		}

		// Duplicate oldimage rows (same timestamp and sha1, see T67264) are migrated as one row
		$missing = count( array_diff_key( $oldVersions, $oldRevisions ) );
		if ( $missing ) {
			$this->report( $name, "$missing old version(s) from oldimage are missing in filerevision" );
		}
		$extra = count( array_diff_key( $oldRevisions, $oldVersions ) );
		if ( $extra ) {
			$this->report( $name, "$extra old revision(s) in filerevision are not in oldimage" );
		}
	}

	/**
	 * Live file rows need an image row, and deleted file rows shouldn't have revisions left.
	 */
	private function checkFileRows( IReadableDatabase $dbr, string $start ): void {
		$this->output( "Checking file rows...\n" );
		$after = null;
		do {
			$queryBuilder = $dbr->newSelectQueryBuilder()
				->select( [ 'file_id', 'file_name', 'file_deleted', 'img_name' ] )
				->from( 'file' )
				->leftJoin( 'image', null, 'img_name = file_name' )
				->orderBy( 'file_name', SelectQueryBuilder::SORT_ASC )
				->limit( $this->getBatchSize() );
			if ( $after !== null ) {
				$queryBuilder->where( $dbr->expr( 'file_name', '>', $after ) );
			} elseif ( $start !== '' ) {
				$queryBuilder->where( $dbr->expr( 'file_name', '>=', $start ) );
			}
			$rows = iterator_to_array( $queryBuilder->caller( __METHOD__ )->fetchResultSet() );
			if ( !$rows ) {
				break;
			}
			$after = end( $rows )->file_name;

			$deletedFiles = [];
			foreach ( $rows as $row ) {
				if ( (int)$row->file_deleted !== 0 ) {
					$deletedFiles[$row->file_id] = $row->file_name;
				} elseif ( $row->img_name === null ) {
					$this->report( $row->file_name, 'live in the file table, but not in the image table' );
				}
			}
			if ( $deletedFiles ) {
				$res = $dbr->newSelectQueryBuilder()
					->select( [ 'fr_file', 'count' => 'COUNT(*)' ] )
					->from( 'filerevision' )
					->where( [ 'fr_file' => array_keys( $deletedFiles ) ] )
					->groupBy( 'fr_file' )
					->caller( __METHOD__ )->fetchResultSet();
				foreach ( $res as $row ) {
					$this->report(
						$deletedFiles[$row->fr_file],
						"deleted in the file table, but still has {$row->count} filerevision row(s)"
					);
				}
			}
		} while ( count( $rows ) === $this->getBatchSize() );
	}

	/**
	 * Every filerevision row needs a file row.
	 */
	private function checkOrphanedRevisions( IReadableDatabase $dbr ): void {
		$this->output( "Checking for filerevision rows without a file row...\n" );
		$after = 0;
		do {
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'fr_id', 'fr_file' ] )
				->from( 'filerevision' )
				->leftJoin( 'file', null, 'file_id = fr_file' )
				->where( [
					$dbr->expr( 'fr_id', '>', $after ),
					'file_id' => null
				] )
				->orderBy( 'fr_id', SelectQueryBuilder::SORT_ASC )
				->limit( $this->getBatchSize() )
				->caller( __METHOD__ )->fetchResultSet();

			foreach ( $res as $row ) {
				$this->problems++;
				$this->output(
					"filerevision row {$row->fr_id} points to file_id {$row->fr_file}, " .
					"which doesn't exist\n"
				);
				$after = (int)$row->fr_id;
			}
		} while ( $res->numRows() === $this->getBatchSize() );
	}

	private function revisionKey( string $timestamp, string $sha1 ): string {
		return wfTimestamp( TS::MW, $timestamp ) . '|' . rtrim( $sha1, "\0" );
	}

	private function report( string $name, string $problem ): void {
		$this->problems++;
		$this->output( "File:$name: $problem\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = CheckFileTables::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
