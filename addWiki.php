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
use MediaWiki\Installer\DatabaseCreator;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\WikiMap\WikiMap;
use Wikibase\Lib\Maintenance\PopulateSitesTable;

class AddWiki extends InstallPreConfigured {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Add a new wiki to the family. Wikimedia specific!" );
		$this->addOption( 'allow-existing',
			'Allow the script to run on an existing wiki' );
	}

	public function getDbType() {
		return Maintenance::DB_ADMIN;
	}

	public function execute() {
		if ( $this->hasArg() ) {
			$this->fatalError( "This script no longer takes arguments. " .
				"Deploy the configuration first, and then use --wiki=<wiki-to-create>.\n" );
		}

		$this->checkExistingWiki();
		$this->checkLanguageName();

		if ( !parent::execute() ) {
			return false;
		}

		$this->output( "Core installer complete\n" );

		$this->populateSites();
		$this->setZoneAccess();
		$this->updateSearchIndexConfig();
		$this->notifyNewProjects();

		$this->output( "Done.\n" );
		return true;
	}

	protected function getSubclassDefaultOptions() {
		global $wgConf;
		$options = [];

		$lang = $this->getConfig()->get( MainConfigNames::LanguageCode );
		$options['LanguageNameInEnglish'] = $this->getServiceContainer()->getLanguageNameUtils()
			->getLanguageName( $lang, 'en' );

		global $wgSiteMatrixSites;
		[ $site, ] = $wgConf->siteFromDB( WikiMap::getCurrentWikiId() );
		$options['SiteGroupInEnglish'] = $wgSiteMatrixSites[$site]['name']
			?? ucfirst( $site ?? 'wikipedia' );

		return $options;
	}

	/**
	 * Check if the wiki already exists. This is in case of confusion with the old
	 * addWiki.php which didn't act on the wiki specified with --wiki.
	 */
	private function checkExistingWiki() {
		$dbName = $this->getConfig()->get( MainConfigNames::DBname );
		if ( !$this->hasOption( 'allow-existing' ) ) {
			$dbCreator = DatabaseCreator::createInstance( $this->getTaskContext() );
			if ( $dbCreator->existsLocally( $dbName ) ) {
				$this->fatalError( "The wiki \"$dbName\" already exists. " .
					"Use --allow-existing to run installer tasks on an existing wiki." );
			}
		}
	}

	/**
	 * Check if the configured language code exists in the name list
	 */
	private function checkLanguageName() {
		$lang = $this->getConfig()->get( MainConfigNames::LanguageCode );
		$languageNames = $this->getServiceContainer()->getLanguageNameUtils()
			->getLanguageNames();
		if ( !isset( $languageNames[$lang] ) ) {
			$this->fatalError( "Language $lang not found in Names.php" );
		}
	}

	/**
	 * Populate the sites table
	 *
	 * TODO: move to core. Move the weird bits to config.
	 */
	private function populateSites() {
		$extDir = $this->getConfig()->get( MainConfigNames::ExtensionDirectory );
		// Populate sites table (this should be idempotent)
		// At least it's idempotent in the sense that it will give you the same fatal error every time
		$this->runInstallScript(
			PopulateSitesTable::class,
			"$extDir/Wikibase/lib/maintenance/populateSitesTable.php",
			[
				'wiki' => WikiMap::getCurrentWikiId(),
				'force-protocol' => 'https',
			],
		);
	}

	/**
	 * Set up Swift zones
	 *
	 * TODO: move to core
	 */
	private function setZoneAccess() {
		$extDir = $this->getConfig()->get( MainConfigNames::ExtensionDirectory );
		$options = [
			'backend' => 'local-multiwrite'
		];
		if ( $this->isPrivate() ) {
			$options['private'] = 1;
		}
		// Sets up the filebackend zones (this should be idempotent)
		$this->runInstallScript(
			SetZoneAccess::class,
			"$extDir/WikimediaMaintenance/filebackend/setZoneAccess.php",
			$options
		);
	}

	/**
	 * Set up ElasticSearch namespaces
	 *
	 * TODO: move to ElasticSearch install task or update
	 */
	private function updateSearchIndexConfig() {
		$extDir = $this->getConfig()->get( MainConfigNames::ExtensionDirectory );
		$this->runInstallScript(
			UpdateSearchIndexConfig::class,
			"$extDir/CirrusSearch/maintenance/UpdateSearchIndexConfig.php",
			[ 'cluster' => 'all' ]
		);
	}

	/**
	 * Send an email to the newprojects mailing list
	 */
	private function notifyNewProjects() {
		global $wmgAddWikiNotify, $wgConf;

		$config = $this->getConfig();

		$wiki = WikiMap::getCurrentWikiId();
		$to = new MailAddress( $wmgAddWikiNotify );
		$from = new MailAddress( $config->get( MainConfigNames::PasswordSender ) );
		$user = getenv( 'SUDO_USER' );
		$time = wfTimestamp( TS_RFC2822 );

		$lang = $config->get( MainConfigNames::LanguageCode );
		[ $site, $prefix ] = $wgConf->siteFromDB( $wiki );

		$url = $this->getServiceContainer()->getUrlUtils()->expand( '/' );

		if ( $lang === $prefix ) {
			$langName = $this->getServiceContainer()
				->getLanguageNameUtils()
				->getLanguageName( $lang, 'en' );
			$ucSiteGroup = $this->getTaskContext()->getOption( 'SiteGroupInEnglish' );
			$body = "A new wiki was created by $user at $time for a $ucSiteGroup in $langName ($lang).\n" .
				"Once the wiki is fully set up, it'll be visible at $url";
		} else {
			$siteName = $config->get( MainConfigNames::Sitename );
			$body = "A new wiki was created by $user at $time for \"$siteName\".\n" .
				"Once the wiki is fully set up, it'll be visible at $url";
		}

		$this->output( "Notifying $to... " );
		$status = UserMailer::send( $to, $from, "New wiki: $wiki", $body );
		if ( !$status->isOK() ) {
			$this->error( $status );
		} else {
			$this->output( "done\n" );
		}
	}

	/**
	 * Check if the wiki is private
	 *
	 * @return bool
	 */
	private function isPrivate() {
		return in_array( WikiMap::getCurrentWikiId(), MWWikiversions::readDbListFile( 'private' ) );
	}

	/**
	 * Run a maintenance script, with some informative messaging
	 *
	 * @param string $class
	 * @param string $classFile
	 * @param array $options
	 */
	private function runInstallScript( $class, $classFile, $options ) {
		$wiki = WikiMap::getCurrentWikiId();
		$maint = $this->runChild( $class, $classFile );
		$baseName = basename( $classFile );
		$cmd = "$baseName --wiki=$wiki";
		foreach ( $options as $name => $value ) {
			// escapeshellcmd looks nicer than escapeshellarg. This is just a
			// suggested command for the admin to paste, not derived from user input.
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellcmd
			$cmd .= ' ' . escapeshellcmd( "--$name=$value" );
			$maint->setOption( $name, $value );
		}
		$this->output( "\nRunning maintenance script class as if executing: $cmd\n" );
		if ( $maint->execute() !== false ) {
			$this->output( "$baseName complete\n" );
		} else {
			$this->fatalError( "$baseName failed\n" );
		}
	}

}

$maintClass = AddWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
