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

/**
 * Delete the entries from the text table based on a given file.
 */
class MigrateESRefToContentTableStage2 extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addOption( 'dry-run', 'Don\'t modify any rows' );
		$this->addOption(
			'sleep',
			'Sleep time (in seconds) between every batch. Default: 0',
			false,
			true
		);
		$this->addOption(
			'delete',
			'List of tt: references to be deleted',
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

		$filename = $this->getOption( 'delete', false );
		$delete = [];
		if ( $filename ) {
			$deletefile = file( $filename );
			$delete = $deletefile ?: $delete;
		}

		$total = count( $delete );
		$count = 0;

		foreach ( $delete as $id ) {
			$oldId = intval( substr( $id, 3 ) );

			if ( !$oldId ) {
				$this->output( "Malformed text_id: {$oldId}\n" );
				continue;
			}

			if ( !$dryRun ) {
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'text' )
					->where( [ 'old_id' => $oldId ] )
					->caller( __METHOD__ )
					->execute();
			} else {
				$this->output( "DRY-RUN: Would delete text row {$oldId}.\n" );
			}

			$count++;

			if ( $count % $batchSize == 0 ) {
				$this->waitForReplication();
				if ( $sleep > 0 ) {
					if ( $sleep >= 1 ) {
						sleep( (int)$sleep );
					} else {
						usleep( (int)( $sleep * 1000000 ) );
					}
				}

				$this->output( "Processed {$count} rows out of {$total}.\n" );
			}
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = MigrateESRefToContentTableStage2::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
