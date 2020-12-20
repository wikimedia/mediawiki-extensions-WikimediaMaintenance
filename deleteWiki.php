<?php
/**
 * Cleanup references to a deleted wiki. Assumes MySQL because it's written for WMF usage.
 *
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

require_once __DIR__ . '/WikimediaMaintenance.php';

class DeleteWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Cleans up databases from references to a deleted wiki, but does '
			. 'not delete anything from the wiki itself.'
		);

		$this->addArg( 'wiki', 'Wiki being deleted', true );
		$this->setBatchSize( 500 );
	}

	/**
	 * Nuke all the datas!
	 */
	public function execute() {
		$wiki = $this->getArg( 0 );

		echo "ATTENTION: All references to $wiki are going to be irreversibly DELETED! "
			. "Type `yes I'm sure` to confirm: ";
		$confirmation = trim( fgets( STDIN ) );
		if ( strcasecmp( $confirmation, "yes I'm sure" ) !== 0 ) {
			$this->fatalError( "\nD'oh, then not deleting anything!\n" );
		}

		$this->output( "Cleaning up global image links...\n" );
		$this->cleanupWikiDb( 'commonswiki', "DELETE FROM globalimagelinks WHERE gil_wiki='$wiki'" );

		$this->output( "Cleaning up CentralAuth localnames...\n" );
		$this->cleanupWikiDb( 'centralauth', "DELETE FROM localnames WHERE ln_wiki='$wiki'" );

		$this->output( "Cleaning up CentralAuth localuser...\n" );
		$this->cleanupWikiDb( 'centralauth', "DELETE FROM localuser WHERE lu_wiki='$wiki'" );

		$this->output( "Cleaning up CentralAuth renameuser_status...\n" );
		$this->cleanupWikiDb( 'centralauth', "DELETE FROM renameuser_status WHERE ru_wiki='$wiki'" );

		// @TODO: wikiset

		$this->output( "Cleaning up CentralAuth renameuser_queue...\n" );
		$this->cleanupWikiDb( 'centralauth', "DELETE FROM renameuser_queue WHERE rq_wiki='$wiki'" );

		$this->output( "Cleaning up CentralAuth users_to_rename...\n" );
		$this->cleanupWikiDb( 'centralauth', "DELETE FROM users_to_rename WHERE utr_wiki='$wiki'" );
	}

	/**
	 * Perform cleanup of a wiki
	 *
	 * @param string $wiki Wiki to operate on (NOT the wiki being deleted!)
	 * @param string $query DELETE query to run in batches
	 */
	private function cleanupWikiDb( $wiki, $query ) {
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB( $wiki );
		$dbw = $lb->getConnection( DB_MASTER );

		$count = 0;
		$batchSize = $this->getBatchSize();
		do {
			$dbw->query( "$query LIMIT {$batchSize}", __METHOD__ );
			$lbFactory->waitForReplication( [ 'wiki' => $wiki ] );
			$count += $dbw->affectedRows();
			$this->output( "  $count\n" );
		} while ( $dbw->affectedRows() >= $batchSize );

		// The DELETE+LIMIT queries passed in are not replication-safe by themselves. However,
		// as long as all rows meeting the WHERE clause are deleted, then all replicas will
		// converge. For sanity, issue one final DELETE query to assure this.
		$dbw->query( $query, __METHOD__ );

		$lb->reuseConnection( $dbw );
	}
}

$maintClass = DeleteWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
