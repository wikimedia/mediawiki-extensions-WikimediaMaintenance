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
 * @ingroup Wikimedia
 */

require_once( __DIR__ . '/WikimediaMaintenance.php' );

class CleanupPageProps extends Maintenance {
	public function __construct() {
		$this->mDescription = 'Cleans up page_propertes table from obsolete entries';
		$this->addOption( 'wait-after', 'Wait for changes to be replicated after this number of rows is '
			. 'deleted' );
		$this->setBatchSize( 1000 ); // This large because not every row in batch needs to be deleted
	}

	public function execute() {
		$dbw = wfGetDB( DB_MASTER );
		$high = $dbw->selectField( 'page_props', 'MAX(pp_page)', '', __METHOD__ );
		$waitAfter = $this->getOption( 'wait-after', 500 );
		$deleted = 0;
		for ( $id = 0; $id <= $high; $id += $this->mBatchSize ) {
			$dbw->delete( 'page_props',
				array(
					"pp_page BETWEEN $id AND $id + {$this->mBatchSize}",
					// Clean up bogus entries left by MobileFrontend
					'pp_propname' => 'page_top_level_section_count',
					'pp_value' => 0,
				),
				__METHOD__
			);
			$affected = $dbw->affectedRows();
			$deleted += $affected;
			if ( $deleted >= $waitAfter ) {
				wfWaitForSlaves();
				$deleted = 0;
			}
			$this->output( "$id, deleted $affected rows\n" );
		}
	}
}

$maintClass = 'CleanupPageProps';
require_once RUN_MAINTENANCE_IF_MAIN;
