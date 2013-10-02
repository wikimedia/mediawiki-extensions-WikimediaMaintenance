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

class RemoveDeletedWikisFromGlobalUsage extends WikimediaMaintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = 'Remove any remaining entries in globalimagelinks for deleted wikis';
	}

	function execute() {
		$wikis = file( '/a/common/deleted.dblist' );
		if ( $wikis === false ) {
			$this->error( 'Unable to open deleted.dblist', 1 );
		}
		$dbw = $this->getDB( DB_MASTER );
		if ( !$dbw->tableExists( 'globalimagelinks' ) ) {
			$this->error( 'Maintenance script cannot be run on this wiki as there is no globalimagelinkstable', 1 );
		}
		foreach ( $wikis as $wiki ) {
			$count = 0;
			$wiki = rtrim( $wiki );
			$this->output( "$wiki:\n" );

			do {
				// https://bugzilla.wikimedia.org/show_bug.cgi?id=52868
				//$dbw->delete(
				//	'globalimagelinks',
				//	array( 'gil_wiki' => $wiki ),
				//	__METHOD__,
				//	array( 'LIMIT' => 500 ),
				//);
				$wikiQuoted = $dbw->addQuotes( $wiki );
				$dbw->query(
					"DELETE FROM globalimagelinks WHERE gil_wiki=$wikiQuoted LIMIT 500",
					__METHOD__
				);
				$affected = $dbw->affectedRows();
				$count += $affected;
				$this->output( "$count\n" );
				wfWaitForSlaves();
			} while ( $affected === 500 );

			$this->output( "$wiki: $count globalimagelinks rows deleted\n" );
		}
		$this->output( "Done.\n" );
	}
}

$maintClass = 'RemoveDeletedWikisFromGlobalUsage';
require_once( DO_MAINTENANCE );
