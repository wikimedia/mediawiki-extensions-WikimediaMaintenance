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
 */

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Storage\SqlBlobStore;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Remove references to the local text table.
 *
 * Look for entries in the content table which reference the local text table,
 * which in turn references external storage. Change these to directly reference
 * external storage in the content table.
 *
 * Use migrateESRefToContentTableStage2.php to rid of the rows in the text table.
 */
class MigrateESRefToContentTable extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addOption( 'start', 'start content_id', false, true, 's' );
		$this->addOption( 'end', 'end content_id', false, true, 'e' );
		$this->addOption( 'dry-run', 'Don\'t modify any rows' );
		$this->addOption(
			'sleep',
			'Sleep time (in seconds) between every batch. Default: 0',
			false,
			true
		);
		$this->addOption(
			'skip',
			'List of tt: references to not delete',
			false,
			true
		);
		$this->addOption(
			'dump',
			'Filename to dump tt: -> es:DB references to.',
			false,
			true
		);

		$this->setBatchSize( 100 );
	}

	public function execute() {
		$dbw = $this->getPrimaryDB();
		$batchSize = $this->getBatchSize();
		$dryRun = $this->getOption( 'dry-run', false );
		$sleep = (float)$this->getOption( 'sleep', 0 );

		$maxID = $this->getOption( 'end' );
		if ( $maxID === null ) {
			$maxID = $dbw->newSelectQueryBuilder()
				->select( 'content_id' )
				->from( 'content' )
				->where( $dbw->expr(
					'content_address',
					IExpression::LIKE,
					new LikeValue( 'tt:', $dbw->anyString() )
				) )
				->orderBy( 'content_id', SelectQueryBuilder::SORT_DESC )
				->limit( 1 )
				->caller( __METHOD__ )
				->fetchField();
		}
		$maxID = (int)$maxID;
		$minID = (int)$this->getOption( 'start', 1 );

		$diff = $maxID - $minID + 1;

		$filename = $this->getOption( 'skip', false );
		$skip = [];
		if ( $filename ) {
			$skipfile = file( $filename );
			$skip = $skipfile ?: $skip;
		}

		// Put the values as keys to make the checks much faster
		if ( $skip ) {
			$skip = array_flip( $skip );
		}

		$dump = $this->getOption( 'dump', false );
		$dumpfile = null;
		if ( $dump ) {
			$dumpfile = fopen( $dump, 'a' );
		}

		while ( true ) {
			$res = $dbw->newSelectQueryBuilder()
				->select( [ 'content_id', 'content_address' ] )
				->from( 'content' )
				->conds( [
					$dbw->expr( 'content_id', '>=', $minID ),
					$dbw->expr( 'content_id', '<', $minID + $batchSize ),
				] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $res as $row ) {
				try {
					[ $schema, $id, ] = SqlBlobStore::splitBlobAddress( $row->content_address );
				} catch ( InvalidArgumentException $ex ) {
					$this->output( $ex->getMessage() . ". Use findBadBlobs.php to remedy.\n" );
					continue;
				}

				// Skip blobs which already reference external storage directly
				if ( $schema === 'es' ) {
					continue;
				}

				// Skip bad blobs
				if ( $schema !== 'tt' ) {
					$this->output( "content id {$row->content_id} has special stuff: {$row->content_address}\n" );
					continue;
				}

				$oldId = intval( $id );

				if ( !$oldId ) {
					$this->output( "Malformed text_id: $oldId\n" );
					continue;
				}

				$textRow = $dbw->newSelectQueryBuilder()
					->select( [ 'old_text', 'old_flags' ] )
					->from( 'text' )
					->where( [ 'old_id' => $oldId ] )
					->caller( __METHOD__ )
					->fetchRow();

				if ( !$textRow ) {
					$this->output( "Text row for blob {$row->content_id} is missing.\n" );
					continue;
				}

				$flags = SqlBlobStore::explodeFlags( $textRow->old_flags );

				if ( !in_array( 'external', $flags ) ) {
					$this->output( "old id {$oldId} is not external.\n" );
					continue;
				}

				$newFlags = implode( ',', array_filter(
					$flags,
					static function ( $v ) {
						return $v !== 'external';
					}
				) );
				$newContentAddress = 'es:' . $textRow->old_text . '?flags=' . $newFlags;

				if ( !$dryRun ) {
					$dbw->newUpdateQueryBuilder()
						->update( 'content' )
						->set( [ 'content_address' => $newContentAddress ] )
						->where( [ 'content_id' => $row->content_id ] )
						->caller( __METHOD__ )
						->execute();

					if ( !array_key_exists( $row->content_address . "\n", $skip ) ) {
						$dbw->newDeleteQueryBuilder()
							->deleteFrom( 'text' )
							->where( [ 'old_id' => $oldId ] )
							->caller( __METHOD__ )
							->execute();
					}

					if ( $dumpfile ) {
						fwrite(
							$dumpfile,
							$row->content_address . " => " . $newContentAddress . ";\n"
						);
					}
				} else {
					$this->output( "DRY-RUN: Would set content address for {$row->content_id} to "
						. "{$newContentAddress} and delete text row {$oldId}.\n" );
				}
			}

			$this->waitForReplication();
			if ( $sleep > 0 ) {
				if ( $sleep >= 1 ) {
					sleep( (int)$sleep );
				} else {
					usleep( (int)( $sleep * 1000000 ) );
				}
			}

			$this->output( "Processed {$res->numRows()} rows out of $diff.\n" );

			$minID += $batchSize;
			if ( $minID > $maxID ) {
				break;
			}
		}

		if ( $dumpfile ) {
			fclose( $dumpfile );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = MigrateESRefToContentTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
