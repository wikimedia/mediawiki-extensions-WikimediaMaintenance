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

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\MediaWikiServices;

class GetPageCounts extends Maintenance {
	public function __construct() {
		$this->addDescription(
			'Generates machine-readable statistics of pages on all wikis in the cluster'
		);
		parent::__construct();
	}

	public function execute() {
		global $wgConf;

		$wikis = $wgConf->getLocalDatabases();
		$exclude = array_flip( $this->getExcludedWikis() );
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$counts = [];
		foreach ( $wikis as $wiki ) {
			$wiki = trim( $wiki );
			if ( $wiki === '' || $wiki[0] === '#' ) {
				continue;
			}
			if ( isset( $exclude[$wiki] ) ) {
				continue;
			}
			$lb = $lbFactory->getMainLB( $wiki );
			$dbr = $lb->getConnection( DB_REPLICA, [], $wiki );
			$row = $dbr->selectRow(
				'site_stats',
				[ 'ss_total_pages', 'ss_good_articles' ],
				'',
				__METHOD__
			);
			if ( !$row ) {
				$this->fatalError( "Error: '$wiki' has empty site_stats" );
			}
			$counts[$wiki] = [
				'pages' => intval( $row->ss_total_pages ),
				'contentPages' => intval( $row->ss_good_articles ),
			];
		}
		$this->output( FormatJson::encode( $counts, true ) . "\n" );
	}

	private function getExcludedWikis() {
		return $this->dblist( 'private' );
	}

	private function dblist( $name ) {
		if ( !defined( 'MEDIAWIKI_DEPLOYMENT_DIR' ) ) {
			$this->error(
				"Warning: MEDIAWIKI_DEPLOYMENT_DIR is not defined, no wikis will be ignored\n"
			);
			return [];
		}
		$fileName = MEDIAWIKI_DEPLOYMENT_DIR . "/dblists/$name.dblist";
		if ( !is_readable( $fileName ) ) {
			$this->error( "Warning: can't read $fileName, no wikis will be ignored\n" );
			return [];
		}
		return array_map( 'trim', file( $fileName ) );
	}
}

$maintClass = GetPageCounts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
