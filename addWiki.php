<?php
/**
 * @defgroup Wikimedia Wikimedia
 */

/**
 * Add a new wiki
 * Wikimedia specific!
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

require_once __DIR__ . '/WikimediaMaintenance.php';

use CirrusSearch\Maintenance\UpdateSearchIndexConfig;
use Cognate\PopulateCognateSites;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use Wikibase\Lib\Maintenance\PopulateSitesTable;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\LBFactory;

class AddWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Add a new wiki to the family. Wikimedia specific!" );
		$this->addArg( 'language', 'Language code of new site, e.g. en' );
		$this->addArg( 'site', 'Type of site, e.g. wikipedia' );
		$this->addArg( 'dbname', 'Name of database to create, e.g. enwiki' );
		$this->addArg( 'domain', 'Domain name of the wiki, e.g. en.wikipedia.org' );
		$this->addOption( 'skipclusters',
			'Comma-separated DB clusters to skip schema changes for (main,extstore,echo,ext1)',
			false,
			true
		);
	}

	public function getDbType() {
		return Maintenance::DB_ADMIN;
	}

	/**
	 * Used as an override from SQL commands in tables.sql being executed.
	 * In this cases, index creations on the searchindex table
	 *
	 * @param string $cmd
	 * @return bool
	 */
	public function noExecuteCommands( $cmd ) {
		return strpos( $cmd, 'ON /*_*/searchindex' ) === false;
	}

	public function execute() {
		global $IP, $wmgVersionNumber, $wmgAddWikiNotify,
			$wgPasswordSender, $wgDBname, $wgEchoCluster;

		if ( !$wmgVersionNumber ) { // set in CommonSettings.php
			$this->fatalError( '$wmgVersionNumber is not set, please use MWScript.php wrapper.' );
		}

		$lang = $this->getArg( 0 );
		$siteGroup = $this->getArg( 1 );
		$dbName = $this->getArg( 2 );
		$domain = $this->getArg( 3 );
		$skipClusters = explode( ',', $this->getOption( 'skipclusters', '' ) );

		$languageNames = Language::fetchLanguageNames();

		if ( $siteGroup === 'wiktionary' && strpos( $wgDBname, 'wiktionary' ) === false ) {
			$this->fatalError(
				'Wiktionaries must be created using --wiki aawiktionary ' .
					'due to the need to load Cognate classes.'
			);
		}

		if ( !isset( $languageNames[$lang] ) ) {
			$this->fatalError( "Language $lang not found in Names.php" );
		}
		$name = $languageNames[$lang];

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$localLb = $lbFactory->getMainLB();

		$this->output( "Creating database $dbName for $lang.$siteGroup ($name)\n" );

		if ( !in_array( 'main', $skipClusters, true ) ) {
			// Set up the database on the same shard as the wiki this script is running on
			$conn = $localLb->getConnection( DB_MASTER, [], $localLb::DOMAIN_ANY );
			$conn->query( "SET storage_engine=InnoDB" );
			$conn->query( "CREATE DATABASE IF NOT EXISTS $dbName" );
			$localLb->closeConnection( $conn );
		}

		// Close connections and make future ones use the new database as the local domain
		$lbFactory->redefineLocalDomain( $dbName );

		// Get a connection to the new database
		$dbw = $localLb->getMaintenanceConnectionRef( DB_MASTER );
		if ( $dbw->getDBname() !== $dbName ) { // sanity
			$this->fatalError( "Expected connection to '$dbName', not '{$dbw->getDBname()}'" );
		}

		if ( !in_array( 'main', $skipClusters, true ) ) {
			// Create all required tables from core and extensions
			$this->createMainClusterSchema( $dbw, $dbName, $siteGroup );
		}

		if (
			!in_array( 'echo', $skipClusters, true ) &&
			!( !$wgEchoCluster && in_array( 'main', $skipClusters, true ) )
		) {
			// Initialise Echo cluster if applicable.
			// It will create the Echo tables in the main database if
			// extension1 is not in use.
			$echoLB = $wgEchoCluster ? $lbFactory->getExternalLB( $wgEchoCluster ) : $localLb;
			$conn = $echoLB->getConnection( DB_MASTER, [], $localLb::DOMAIN_ANY );
			$conn->query( "SET storage_engine=InnoDB" );
			$conn->query( "CREATE DATABASE IF NOT EXISTS $dbName" );
			$echoLB->closeConnection( $conn );

			$echoDbW = $echoLB->getMaintenanceConnectionRef( DB_MASTER );
			$echoDbW->sourceFile( "$IP/extensions/Echo/echo.sql" );
		}

		if ( !in_array( 'extstore', $skipClusters, true ) ) {
			$this->createExternalStoreClusterSchema( $dbName, $lbFactory );
		}

		// T212881: Redefine the RevisionStore service to explicitly use the new DB name.
		// Otherwise, ExternalStoreDB would be instantiated with an implicit database domain,
		// causing it to use the DB name of the wiki the script is running on due to T200471.
		MediaWikiServices::getInstance()->redefineService(
			'RevisionStore',
			function ( MediaWikiServices $services ) use ( $dbName ): RevisionStore {
				return $services->getRevisionStoreFactory()->getRevisionStore( $dbName );
			}
		);

		// Make the main page (this should be idempotent)
		$title = Title::newFromText( wfMessage( 'mainpage' )->inLanguage( $lang )
			->useDatabase( false )->plain() );
		$this->output( "Writing main page to " . $title->getPrefixedDBkey() . "\n" );
		$wikiPage = WikiPage::factory( $title );
		$ucSiteGroup = ucfirst( $siteGroup );
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		$summary = CommentStoreComment::newUnsavedComment( '' );

		$updater = $wikiPage->newPageUpdater( $user );
		$updater->setContent(
			SlotRecord::MAIN,
			ContentHandler::makeContent( $this->getFirstArticle( $ucSiteGroup, $name ), $title )
		);
		$updater->saveRevision(
			$summary,
			EDIT_NEW | EDIT_AUTOSUMMARY
		);

		$this->setFundraisingLink( $domain, $lang );

		// Create new search index (this should be idempotent)
		global $wgCirrusSearchWriteClusters, $wgCirrusSearchClusters;

		$writableClusters = $wgCirrusSearchWriteClusters;
		if ( $writableClusters === null ) {
			$writableClusters = array_keys( $wgCirrusSearchClusters );
		}

		foreach ( $writableClusters as $cluster ) {
			$searchIndex = $this->runChild( UpdateSearchIndexConfig::class );
			$searchIndex->mOptions[ 'baseName' ] = $dbName;
			$searchIndex->mOptions[ 'cluster' ] = $cluster;
			$searchIndex->execute();
		}

		// Populate sites table (this should be idempotent)
		$sitesPopulation = $this->runChild(
			PopulateSitesTable::class,
			"$IP/extensions/Wikibase/lib/maintenance/populateSitesTable.php"
		);

		$sitesPopulation->setDB( $dbw );
		$sitesPopulation->mOptions[ 'site-group' ] = $siteGroup;
		$sitesPopulation->mOptions[ 'force-protocol' ] = 'https';
		$sitesPopulation->execute();

		// Repopulate Cognate sites table (this should be idempotent)
		if ( $siteGroup === 'wiktionary' ) {
			$cognateSitesPopulation = $this->runChild(
				PopulateCognateSites::class,
				"$IP/extensions/Cognate/maintenance/populateCognateSites.php"
			);
			$cognateSitesPopulation->setDB( $dbw );
			$cognateSitesPopulation->mOptions[ 'site-group' ] = $siteGroup;
			$cognateSitesPopulation->execute();
		}

		// Sets up the filebackend zones (this should be idempotent)
		$setZones = $this->runChild(
			SetZoneAccess::class,
			"$IP/extensions/WikimediaMaintenance/filebackend/setZoneAccess.php"
		);

		$setZones->setDB( $dbw );
		$setZones->mOptions['backend'] = 'local-multiwrite';
		if ( $this->isPrivate( $dbName ) ) {
			$setZones->mOptions['private'] = 1;
		}
		$setZones->execute();

		// Clear MassMessage cache (bug 60075)
		global $wgConf;
		// Even if the dblists have been updated, it's not in $wgConf yet
		$wgConf->wikis[] = $dbName;
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cache->delete( $cache->makeGlobalKey( 'massmessage', 'urltodb' ) );
		MediaWiki\MassMessage\DatabaseLookup::getDBName( '' ); // Forces re-cache

		$user = getenv( 'SUDO_USER' );
		$time = wfTimestamp( TS_RFC2822 );
		UserMailer::send( new MailAddress( $wmgAddWikiNotify ),
			new MailAddress( $wgPasswordSender ), "New wiki: $dbName",
			"A new wiki was created by $user at $time for a $ucSiteGroup in $name ($lang).\n" .
				"Once the wiki is fully set up, it'll be visible at https://$domain"
		);

		$this->output( "Done. sync the config as in " .
			"https://wikitech.wikimedia.org/wiki/Add_a_wiki#MediaWiki_configuration\n" );
	}

	/**
	 * @param IMaintainableDatabase $dbw
	 * @param string $dbName
	 * @param string $siteGroup
	 * @throws Exception
	 */
	private function createMainClusterSchema( IMaintainableDatabase $dbw, $dbName, $siteGroup ) {
		global $IP;

		$this->output( "Initialising tables\n" );
		$dbw->sourceFile(
			$this->getDir() . '/tables.sql',
			null,
			null,
			__METHOD__,
			[ $this, 'noExecuteCommands' ]
		);
		$dbw->sourceFile( "$IP/extensions/AntiSpoof/sql/patch-antispoof.mysql.sql" );
		$dbw->sourceFile( "$IP/extensions/Babel/babel.sql" );
		$dbw->sourceFile( "$IP/extensions/CheckUser/cu_changes.sql" );
		$dbw->sourceFile( "$IP/extensions/CheckUser/cu_log.sql" );
		$dbw->sourceFile( "$IP/extensions/GlobalBlocking/sql/global_block_whitelist.sql" );
		$dbw->sourceFile( "$IP/extensions/AbuseFilter/abusefilter.tables.sql" );
		$dbw->sourceFile( "$IP/extensions/Math/db/mathoid.mysql.sql" );
		$dbw->sourceFile( "$IP/extensions/TimedMediaHandler/sql/TimedMediaHandler.sql" );
		// Not actually enabled everywhere, but this is easier
		$dbw->sourceFile( "$IP/extensions/GeoData/sql/externally-backed.sql" );
		$dbw->sourceFile( "$IP/extensions/BetaFeatures/sql/create_counts.sql" );
		$dbw->sourceFile( "$IP/extensions/SecurePoll/SecurePoll.sql" );
		$dbw->sourceFile( "$IP/extensions/Linter/sql/linter.sql" );

		// most wikis are wikibase client wikis and no harm to adding this everywhere
		$dbw->sourceFile( "$IP/extensions/Wikibase/client/sql/entity_usage.sql" );

		if ( self::isPrivate( $dbName )
			&& in_array( $dbName, MWWikiversions::readDbListFile( 'flow' ) )
		) {
			// For private wikis, we set $wgFlowDefaultWikiDb = false
			// instead they're on the local database, so create the tables
			$dbw->sourceFile( "$IP/extensions/Flow/flow.sql" );
		}

		// Add project specific extension table additions here
		switch ( $siteGroup ) {
			case 'wikipedia':
				break;
			case 'wiktionary':
				break;
			case 'wikiquote':
				break;
			case 'wikibooks':
				break;
			case 'wikinews':
				break;
			case 'wikisource':
				$dbw->sourceFile( "$IP/extensions/ProofreadPage/sql/ProofreadIndex.sql" );
				break;
			case 'wikiversity':
				break;
			case 'wikimedia':
				break;
			case 'wikidata':
				break;
			case 'wikivoyage':
				$dbw->sourceFile( "$IP/extensions/CreditsSource/schema/mysql/CreditsSource.sql" );
				break;
		}

		if ( self::isPrivateOrFishbowl( $dbName ) ) {
			$dbw->sourceFile( "$IP/extensions/OATHAuth/sql/mysql/tables.sql" );
		}

		$dbw->query( "INSERT INTO site_stats(ss_row_id) VALUES (1)" );
	}

	/**
	 * @param string $dbName
	 * @param LBFactory $lbFactory
	 */
	private function createExternalStoreClusterSchema( $dbName, $lbFactory ) {
		global $wgDefaultExternalStore, $wgFlowExternalStore;

		// Initialise external storage
		if ( is_array( $wgDefaultExternalStore ) ) {
			$stores = $wgDefaultExternalStore;
		} elseif ( $wgDefaultExternalStore ) {
			$stores = [ $wgDefaultExternalStore ];
		} else {
			$stores = [];
		}

		// Flow External Store (may be the same, so there is an array_unique)
		if ( is_array( $wgFlowExternalStore ) ) {
			$flowStores = $wgFlowExternalStore;
		} elseif ( $wgFlowExternalStore ) {
			$flowStores = [ $wgFlowExternalStore ];
		} else {
			$flowStores = [];
		}

		$stores = array_unique( array_merge( $stores, $flowStores ) );

		if ( !count( $stores ) ) {
			return;
		}

		$esFactory = MediaWikiServices::getInstance()->getExternalStoreFactory();
		foreach ( $stores as $storeURL ) {
			$m = [];
			if ( !preg_match( '!^DB://(.*)$!', $storeURL, $m ) ) {
				continue;
			}

			$cluster = $m[1];
			$this->output( "Initialising external storage $cluster...\n" );

			// @note: avoid ExternalStoreDB::getMaster() as that is intended for internal use
			$lb = $lbFactory->getExternalLB( $cluster );

			// Create the database
			$conn = $lb->getConnection( DB_MASTER, [], $lb::DOMAIN_ANY );
			$conn->query( "SET default_storage_engine=InnoDB" );
			// IF NOT EXISTS because two External Store clusters
			// can use the same DB, but different blobs table entries.
			$conn->query( "CREATE DATABASE IF NOT EXISTS $dbName" );
			$lb->closeConnection( $conn );

			// Hack x2
			/** @var ExternalStoreDB $store */
			$store = $esFactory->getStore( 'DB', [ 'domain' => $dbName ] );
			'@phan-var ExternalStoreDB $store';
			$store->initializeTable( $cluster );
		}
	}

	/**
	 * @param string $ucsite
	 * @param string $name
	 * @return string
	 */
	private function getFirstArticle( $ucsite, $name ) {
		// @phpcs:disable Generic.Files.LineLength
		return <<<EOT
==This subdomain is reserved for the creation of a [[wikimedia:Our projects|$ucsite]] in '''[[w:en:{$name}|{$name}]]''' language==

* Please '''do not start editing''' this new site. This site has a test project on the [[incubator:|Wikimedia Incubator]] (or on the [[betawikiversity:|Beta Wikiversity]] or on the [[oldwikisource:|Old Wikisource]]) and it will be imported to here.

* If you would like to help translating the interface to this language, please do not translate here, but go to [[translatewiki:|translatewiki.net]], a special wiki for translating the interface. That way everyone can use it on every wiki using the [[mw:|same software]].

* For information about how to edit and for other general help, see [[m:Help:Contents|Help on Wikimedia's Meta-Wiki]] or [[mw:Help:Contents|Help on MediaWiki.org]].

== Sister projects ==
<span class="plainlinks">
[//www.wikipedia.org Wikipedia] |
[//www.wiktionary.org Wiktionary] |
[//www.wikibooks.org Wikibooks] |
[//www.wikinews.org Wikinews] |
[//www.wikiquote.org Wikiquote] |
[//www.wikisource.org Wikisource] |
[//www.wikiversity.org Wikiversity] |
[//www.wikivoyage.org Wikivoyage] |
[//species.wikimedia.org Wikispecies] |
[//www.wikidata.org Wikidata] |
[//commons.wikimedia.org Commons]
</span>

See Wikimedia's [[m:|Meta-Wiki]] for the coordination of these projects.

EOT;
		// @phpcs:enable Generic.Files.LineLength
	}

	/**
	 * @param string $dbName
	 * @return bool
	 */
	private function isPrivateOrFishbowl( $dbName ) {
		return $this->isPrivate( $dbName ) ||
			in_array( $dbName, MWWikiversions::readDbListFile( 'fishbowl' ) );
	}

	/**
	 * @param string $dbName
	 * @return bool
	 */
	private function isPrivate( $dbName ) {
		return in_array( $dbName, MWWikiversions::readDbListFile( 'private' ) );
	}

	/**
	 * @param string $domain
	 * @param string $language
	 */
	private function setFundraisingLink( $domain, $language ) {
		$title = Title::newFromText( "Mediawiki:Sitesupport-url" );
		$this->output( "Writing sidebar donate link to " . $title->getPrefixedDBkey() . "\n" );
		$wikiPage = WikiPage::factory( $title );

		// There is likely a better way to create the link, but it seems like one
		// cannot count on interwiki links at this point
		$linkurl = "https://donate.wikimedia.org/?" . http_build_query(
			[
				"utm_source" => "donate",
				"utm_medium" => "sidebar",
				"utm_campaign" => $domain,
				"uselang" => $language
			]
		);

		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		$summary = CommentStoreComment::newUnsavedComment( 'Setting sidebar link' );

		$updater = $wikiPage->newPageUpdater( $user );
		$updater->setContent(
			SlotRecord::MAIN,
			ContentHandler::makeContent( $linkurl, $title )
		);
		$updater->saveRevision(
			$summary,
			EDIT_NEW
		);
	}
}

$maintClass = AddWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
