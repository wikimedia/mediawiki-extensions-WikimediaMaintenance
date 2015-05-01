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
require_once( __DIR__ . '/WikimediaMaintenance.php' );

class AddWiki extends WikimediaMaintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Add a new wiki to the family. Wikimedia specific!";
		$this->addArg( 'language', 'Language code of new site, e.g. en' );
		$this->addArg( 'site', 'Type of site, e.g. wikipedia' );
		$this->addArg( 'dbname', 'Name of database to create, e.g. enwiki' );
		$this->addArg( 'domain', 'Domain name of the wiki, e.g. en.wikipedia.org' );
	}

	public function getDbType() {
		return Maintenance::DB_ADMIN;
	}

	/**
	 * Used as an override from SQL commands in tables.sql being executed.
	 * In this cases, index creations on the searchindex table
	 *
	 * @param $cmd string
	 * @return bool
	 */
	public function noExecuteCommands( $cmd ) {
		return strpos( $cmd, 'ON /*_*/searchindex' ) === false;
	}

	public function execute() {
		global $IP, $wgDefaultExternalStore, $wmfVersionNumber;
		if ( !$wmfVersionNumber ) { // set in CommonSettings.php
			$this->error( '$wmfVersionNumber is not set, please use MWScript.php wrapper.', true );
		}

		$lang = $this->getArg( 0 );
		$site = $this->getArg( 1 );
		$dbName = $this->getArg( 2 );
		$domain = $this->getArg( 3 );
		$languageNames = Language::fetchLanguageNames();

		if ( !isset( $languageNames[$lang] ) ) {
			$this->error( "Language $lang not found in Names.php", true );
		}
		$name = $languageNames[$lang];

		$dbw = wfGetDB( DB_MASTER );

		$this->output( "Creating database $dbName for $lang.$site ($name)\n" );

		# Set up the database
		$dbw->query( "SET storage_engine=InnoDB" );
		$dbw->query( "CREATE DATABASE $dbName" );
		$dbw->selectDB( $dbName );

		$this->output( "Initialising tables\n" );
		$dbw->sourceFile(
			$this->getDir() . '/tables.sql',
			false,
			false,
			__METHOD__,
			array( $this, 'noExecuteCommands' )
		);
		$dbw->sourceFile( "$IP/extensions/OAI/sql/update_table.sql" );
		$dbw->sourceFile( "$IP/extensions/AntiSpoof/sql/patch-antispoof.mysql.sql" );
		$dbw->sourceFile( "$IP/extensions/CheckUser/cu_changes.sql" );
		$dbw->sourceFile( "$IP/extensions/CheckUser/cu_log.sql" );
		$dbw->sourceFile( "$IP/extensions/GlobalBlocking/globalblocking.sql" );
		$dbw->sourceFile( "$IP/extensions/AbuseFilter/abusefilter.tables.sql" );
		$dbw->sourceFile( "$IP/extensions/UserDailyContribs/patches/UserDailyContribs.sql" );
		$dbw->sourceFile( "$IP/extensions/Math/db/math.mysql.sql" );
		$dbw->sourceFile( "$IP/extensions/Math/db/mathoid.mysql.sql" );
		$dbw->sourceFile( "$IP/extensions/TimedMediaHandler/TimedMediaHandler.sql" );
		$dbw->sourceFile( "$IP/maintenance/archives/patch-filejournal.sql" );
		$dbw->sourceFile( "$IP/extensions/GeoData/sql/externally-backed.sql" ); // Not actually enabled everywhere, but this is easier
		$dbw->sourceFile( "$IP/extensions/AccountAudit/accountaudit.sql" );
		$dbw->sourceFile( "$IP/extensions/BetaFeatures/sql/create_counts.sql" );
		$dbw->sourceFile( "$IP/extensions/SecurePoll/SecurePoll.sql" );

		// Add project specific extension table additions here
		switch ( $site ) {
			case 'wikipedia':
				break;
			case 'wiktionary':
				break;
			case 'wikiquote':
				break;
			case 'books':
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

		$dbw->query( "INSERT INTO site_stats(ss_row_id) VALUES (1)" );

		// Initialise extension1 cluster (Echo)
		$x1w = MWEchoDbFactory::newFromDefault()->getEchoDb( DB_MASTER );
		$x1w->query( "SET storage_engine=InnoDB" );
		$x1w->query( "CREATE DATABASE $dbName" );
		$x1w->selectDB( $dbName );
		$x1w->sourceFile( "$IP/extensions/Echo/echo.sql" );

		# Initialise external storage
		if ( is_array( $wgDefaultExternalStore ) ) {
			$stores = $wgDefaultExternalStore;
		} elseif ( $wgDefaultExternalStore ) {
			$stores = array( $wgDefaultExternalStore );
		} else {
			$stores = array();
		}
		if ( count( $stores ) ) {
			global $wgDBuser, $wgDBpassword, $wgExternalServers;
			foreach ( $stores as $storeURL ) {
				$m = array();
				if ( !preg_match( '!^DB://(.*)$!', $storeURL, $m ) ) {
					continue;
				}

				$cluster = $m[1];
				$this->output( "Initialising external storage $cluster...\n" );

				# Hack
				$wgExternalServers[$cluster][0]['user'] = $wgDBuser;
				$wgExternalServers[$cluster][0]['password'] = $wgDBpassword;

				$store = new ExternalStoreDB;
				$extdb = $store->getMaster( $cluster );
				$extdb->query( "SET default_storage_engine=InnoDB" );
				$extdb->query( "CREATE DATABASE $dbName" );
				$extdb->selectDB( $dbName );

				# Hack x2
				$blobsTable = $store->getTable( $extdb );
				$sedCmd = "sed s/blobs\\\\\\>/$blobsTable/ " . $this->getDir() . "/storage/blobs.sql";
				$blobsFile = popen( $sedCmd, 'r' );
				$extdb->sourceStream( $blobsFile );
				pclose( $blobsFile );
				$extdb->commit();
			}
		}

		$title = Title::newFromText( wfMessage( 'mainpage' )->inLanguage( $lang )->useDatabase( false )->plain() );
		$this->output( "Writing main page to " . $title->getPrefixedDBkey() . "\n" );
		$article = WikiPage::factory( $title );
		$ucsite = ucfirst( $site );

		$article->doEdit( $this->getFirstArticle( $ucsite, $name ), '', EDIT_NEW | EDIT_AUTOSUMMARY );

		$this->setFundraisingLink( $domain, $lang );

		# Create new search index
		$searchIndex = $this->runChild( 'CirrusSearch\Maintenance\UpdateSearchIndexConfig' );
		$searchIndex->mOptions[ 'baseName' ] = $dbName;
		$searchIndex->execute();

		# Populate sites table
		$sitesPopulation = $this->runChild( 'PopulateSitesTable' );
		$sitesPopulation->mOptions[ 'site-group' ] = $site;
		$sitesPopulation->mOptions[ 'force-protocol' ] = 'https';
		$sitesPopulation->execute();

		# Clear MassMessage cache (bug 60075)
		global $wgMemc, $wgConf;
		// Even if the dblists have been updated, it's not in $wgConf yet
		$wgConf->wikis[] = $dbName;
		$wgMemc->delete( 'massmessage:urltodb' );
		MassMessage::getDBName( '' ); // Forces re-cache

		$user = getenv( 'SUDO_USER' );
		$time = wfTimestamp( TS_RFC2822 );
		UserMailer::send( new MailAddress( $wmgAddWikiNotify ),
			new MailAddress( $wgPasswordSender ), "New wiki: $dbName",
			"A new wiki was created by $user at $time for a $ucsite in $name ($lang).\nOnce the wiki is fully set up, it'll be visible at https://$domain"
		);

		$this->output( "Done. sync the config as in https://wikitech.wikimedia.org/wiki/Add_a_wiki#MediaWiki_configuration" );
	}

	private function getFirstArticle( $ucsite, $name ) {
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
[//www.wikidata.org Wikidata] |
[//commons.wikimedia.org Commons]
</span>

See Wikimedia's [[m:|Meta-Wiki]] for the coordination of these projects.

EOT;
	}

	private function setFundraisingLink( $domain, $language ) {

		$title = Title::newFromText( "Mediawiki:Sitesupport-url" );
		$this->output( "Writing sidebar donate link to " . $title->getPrefixedDBkey() . "\n" );
		$article = WikiPage::factory( $title );

		// There is likely a better way to create the link, but it seems like one
		// cannot count on interwiki links at this point
		$linkurl = "https://donate.wikimedia.org/?" . http_build_query( array(
			"utm_source" => "donate",
			"utm_medium" => "sidebar",
			"utm_campaign" => $domain,
			"uselang" => $language
		) );

		return $article->doEdit( $linkurl, 'Setting sidebar link', EDIT_NEW );
	}
}

$maintClass = "AddWiki";
require_once( RUN_MAINTENANCE_IF_MAIN );
