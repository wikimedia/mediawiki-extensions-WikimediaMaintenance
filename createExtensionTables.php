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
use MediaWiki\WikiMap\WikiMap;

/**
 * Creates the necessary tables to install various extensions on a WMF wiki
 */
class CreateExtensionTables extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Creates database tables for specific MediaWiki Extensions' );
		$this->addArg( 'extension', 'Which extension to install' );
	}

	public function execute() {
		global $IP, $wgFlowDefaultWikiDb, $wgEchoCluster, $wgGEDatabaseCluster, $wgVirtualDomainsMapping;

		$dbw = $this->getDB( DB_PRIMARY );
		$extension = $this->getArg( 0 );

		$files = [];
		$path = '';

		switch ( strtolower( $extension ) ) {
			case 'babel':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/Babel/sql";
				break;

			case 'checkuser':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/CheckUser/schema/mysql";
				break;

			case 'discussiontools':
				$files = [
					'discussiontools_subscription' => 'discussiontools_subscription.sql',
					'discussiontools_items' => 'discussiontools_persistent.sql',
				];
				$path = "$IP/extensions/DiscussionTools/sql/mysql";
				break;

			case 'echo':
				$this->output( "Using special database connection for Echo" );

				$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
				$echoLB = $wgEchoCluster
					? $lbFactory->getExternalLB( $wgEchoCluster )
					: $lbFactory->getMainLB();
				$conn = $echoLB->getConnection( DB_PRIMARY, [], $echoLB::DOMAIN_ANY );
				$conn->query( "SET storage_engine=InnoDB", __METHOD__ );
				$conn->query( "CREATE DATABASE IF NOT EXISTS " . WikiMap::getCurrentWikiId(), __METHOD__ );

				$dbw = $echoLB->getConnection( DB_PRIMARY );

				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/Echo/sql/mysql";
				break;

			case 'flaggedrevs':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/FlaggedRevs/backend/schema/mysql";
				break;

			case 'flow':
				if ( $wgFlowDefaultWikiDb !== false ) {
					$this->fatalError(
						"This wiki uses $wgFlowDefaultWikiDb for Flow tables. They don't need to" .
							" be created on the project database, which is the scope of this script."
					);
				}
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/Flow/sql/mysql";
				break;

			case 'geodata':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/GeoData/sql/mysql";
				break;

			case 'growthexperiments':
				$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
				$geLB = $wgGEDatabaseCluster
					? $lbFactory->getExternalLB( $wgGEDatabaseCluster )
					: $lbFactory->getMainLB();
				$conn = $geLB->getConnection( DB_PRIMARY, [], $geLB::DOMAIN_ANY );
				$conn->query( "SET storage_engine=InnoDB", __METHOD__ );
				$conn->query( "CREATE DATABASE IF NOT EXISTS " . WikiMap::getCurrentWikiId(), __METHOD__ );

				$dbw = $geLB->getConnection( DB_PRIMARY );

				$files = [
					'growthexperiments_link_recommendations' => 'growthexperiments_link_recommendations.sql',
					'growthexperiments_link_submissions' => 'growthexperiments_link_submissions.sql',
					'growthexperiments_mentee_data' => 'growthexperiments_mentee_data.sql',
					'growthexperiments_mentor_mentee' => 'growthexperiments_mentor_mentee.sql',
					'growthexperiments_user_impact' => 'growthexperiments_user_impact.sql',
				];
				$path = "$IP/extensions/GrowthExperiments/sql/mysql";
				break;

			case 'linter':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/Linter/sql";
				break;

			case 'mediamoderation':
				$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
				$mmLB = $lbFactory->getMainLB();
				if ( isset( $wgVirtualDomainsMapping['virtual-mediamoderation'] ) ) {
					$config = $wgVirtualDomainsMapping['virtual-mediamoderation'];
					if ( isset( $config['cluster'] ) ) {
						$mmLB = $lbFactory->getExternalLB( $config['cluster'] );
					}
				}
				$conn = $mmLB->getConnection( DB_PRIMARY, [], $mmLB::DOMAIN_ANY );
				$conn->query( "SET storage_engine=InnoDB", __METHOD__ );
				$conn->query( "CREATE DATABASE IF NOT EXISTS " . WikiMap::getCurrentWikiId(), __METHOD__ );

				$dbw = $mmLB->getConnection( DB_PRIMARY );

				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/MediaModeration/schema/mysql";
				break;

			case 'newsletter':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/Newsletter/sql/mysql";
				break;

			case 'oathauth':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/OATHAuth/sql/mysql";
				break;

			case 'oauth':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/OAuth/schema/mysql";
				break;

			case 'ores':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/ORES/sql/mysql";
				break;

			case 'pageassessments':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/PageAssessments/db/mysql";
				break;

			case 'pagetriage':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/PageTriage/sql/mysql";
				break;

			case 'shorturl':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/ShortUrl/schemas";
				break;

			case 'translate':
				$files = [
					'revtag.sql',
					'translate_groupstats.sql',
					'translate_metadata.sql',
					'translate_sections.sql',
					'translate_groupreviews.sql',
					'translate_messageindex.sql',
					'translate_reviews.sql',
				];
				$path = "$IP/extensions/Translate/sql/mysql";
				break;

			case 'wikibase':
				$files = [ 'entity_usage.sql' ];
				$path = "$IP/extensions/Wikibase/client/sql/mysql";
				break;

			case 'wikilove':
				$files = [ 'tables-generated.sql' ];
				$path = "$IP/extensions/WikiLove/patches";
				break;

			default:
				$this->fatalError( "This script is not configured to create tables for $extension\n" );
		}

		$this->output( "Creating $extension tables...\n" );
		foreach ( $files as $table => $file ) {
			if ( !is_numeric( $table ) && $dbw->tableExists( $table ) ) {
				$this->output( "  $table already exists\n" );
				continue;
			}
			$this->output( "  sourcing $file\n" );
			$dbw->sourceFile( "$path/$file" );
		}
		$this->output( "  done!\n" );
	}
}

$maintClass = CreateExtensionTables::class;
require_once RUN_MAINTENANCE_IF_MAIN;
