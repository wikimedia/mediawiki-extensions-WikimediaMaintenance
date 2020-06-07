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

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

require_once __DIR__ . '/WikimediaMaintenance.php';

class RemoveDeletedWikis extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Remove any remaining entries in globalimagelinks, localuser and "
			. "localnames for deleted wikis.\nThis is probably best run against Commons due to "
			. "globalusage tables"
		);
	}

	public function execute() {
		$wikis = file( '/srv/mediawiki/dblists/deleted.dblist' );
		if ( $wikis === false ) {
			$this->fatalError( 'Unable to open deleted.dblist' );
		}

		$dbw = $this->getDB( DB_MASTER );
		$cadbw = CentralAuthUser::getCentralDB();
		foreach ( $wikis as $wiki ) {
			$wiki = rtrim( $wiki );
			$this->output( "$wiki:\n" );

			$this->doDeletes( $dbw, 'globalimagelinks', 'gil_wiki', $wiki );
			$this->doDeletes( $cadbw, 'localnames', 'ln_wiki', $wiki );
			$this->doDeletes( $cadbw, 'localuser', 'lu_wiki', $wiki );
			// @todo: Delete from wikisets
		}
		$this->output( "Done.\n" );
	}

	/**
	 * @param IDatabase $dbw
	 * @param string $table
	 * @param string $column
	 * @param string $wiki
	 */
	private function doDeletes( $dbw, $table, $column, $wiki ) {
		if ( !$dbw->tableExists( $table, __METHOD__ ) ) {
			$this->fatalError( "Maintenance script cannot be run on this wiki as there is no $table table" );
		}
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$this->output( "$table:\n" );
		$count = 0;
		do {
			// https://bugzilla.wikimedia.org/show_bug.cgi?id=52868
			// $dbw->delete(
			// $table,
			// array( $column => $wiki ),
			// __METHOD__,
			// array( 'LIMIT' => 500 ),
			// );
			$wikiQuoted = $dbw->addQuotes( $wiki );
			$dbw->query(
				"DELETE FROM $table WHERE $column=$wikiQuoted LIMIT 500",
				__METHOD__
			);
			$affected = $dbw->affectedRows();
			$count += $affected;
			$this->output( "$count\n" );
			$lbFactory->waitForReplication();
		} while ( $affected === 500 );
		$this->output( "$count $table rows deleted\n" );
	}
}

$maintClass = RemoveDeletedWikis::class;
require_once RUN_MAINTENANCE_IF_MAIN;
