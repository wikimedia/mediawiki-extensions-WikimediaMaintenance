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
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class CreateExtensionTables extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Create the necessary tables for a given MediaWiki extension on a WMF wiki.' );
		$this->addArg( 'extension', 'Which extension to install' );
	}

	public function execute() {
		global $IP, $wgFlowDefaultWikiDb, $wgEchoCluster;

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
				$dbw = $this->getWikiConnection( $echoLB );

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
				$geLB = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
					->getLoadBalancer( 'virtual-growthexperiments' );
				$dbw = $this->getWikiConnection( $geLB );

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
				$mmLB = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
					->getLoadBalancer( 'virtual-mediamoderation' );
				$dbw = $this->getWikiConnection( $mmLB );

				$files = [
					'mediamoderation_scan' => 'tables-generated.sql'
				];
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
					'translate_translatable_bundles.sql',
					'translate_cache.sql',
				];
				$path = "$IP/extensions/Translate/sql/mysql";
				$this->output( "NOTE: You also need to run this script for translate-virtual\n" );
				break;

			case 'translate-virtual':
				$translateLB = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
					->getLoadBalancer( 'virtual-translate' );
				$dbw = $this->getWikiConnection( $translateLB );
				$files = [
					'translate_message_group_subscriptions.sql',
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

		$this->output(
			"Creating $extension tables in database \"{$dbw->getDBname()}\" " .
				"on server \"{$dbw->getServerName()}\"...\n"
		);
		foreach ( $files as $table => $file ) {
			if ( !is_numeric( $table ) && $dbw->tableExists( $table, __METHOD__ ) ) {
				$this->output( "  $table already exists\n" );
				continue;
			}
			$this->output( "  sourcing $file\n" );
			$dbw->sourceFile( "$path/$file" );
		}
		$this->output( "  done!\n" );
	}

	/**
	 * Get a primary connection to the current wiki's database on the given
	 * load balancer, after verifying that the database actually exists there.
	 *
	 * The database should have already been created by addWiki.php. If it is
	 * missing, that may be a sign of a configuration error (e.g. pointing at
	 * the wrong cluster), and silently creating it here would put the tables
	 * in the wrong place (T434314, T429304).
	 */
	private function getWikiConnection( ILoadBalancer $lb ): IDatabase {
		$wikiId = WikiMap::getCurrentWikiId();
		$conn = $lb->getConnection( DB_PRIMARY, [], ILoadBalancer::DOMAIN_ANY );
		$dbExists = (bool)$conn->newSelectQueryBuilder()
			->select( '1' )
			->from( 'information_schema.schemata' )
			->where( [ 'schema_name' => $wikiId ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$dbExists ) {
			$this->fatalError(
				"Database \"$wikiId\" does not exist on cluster \"{$lb->getClusterName()}\".\n" .
				"It should have been created by addWiki.php. Check that the wiki was created\n" .
				"properly and that the configuration points at the correct cluster."
			);
		}

		return $lb->getConnection( DB_PRIMARY );
	}
}

// @codeCoverageIgnoreStart
$maintClass = CreateExtensionTables::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
