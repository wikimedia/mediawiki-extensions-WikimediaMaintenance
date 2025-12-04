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

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

use CirrusSearch\Maintenance\UpdateSearchIndexConfig;
use MediaWiki\Installer\DatabaseCreator;
use MediaWiki\Language\RawMessage;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Status\Status;
use MediaWiki\WikiMap\WikiMap;
use Wikibase\Lib\Maintenance\PopulateSitesTable;

class AddWiki extends InstallPreConfigured {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Add a new wiki to the family. Wikimedia specific!" );
		$this->addOption( 'allow-existing',
			'Allow the script to run on an existing wiki' );
	}

	/** @inheritDoc */
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
		return true;
	}

	protected function getSubclassDefaultOptions(): array {
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

	protected function getExtraTaskSpecs(): array {
		return [
			[
				'name' => 'populate-sites',
				'description' => 'Populating the sites table on the new wiki',
				'after' => 'extension-tables',
				'callback' => function () {
					return $this->populateSites();
				}
			],
			[
				'name' => 'set-zone-access',
				'description' => 'Configuring Swift zones',
				'after' => 'extension-tables',
				'callback' => function () {
					return $this->setZoneAccess();
				}
			],
			[
				'name' => 'search-index',
				'description' => 'Configuring CirrusSearch indexes',
				'after' => 'extension-tables',
				'callback' => function () {
					return $this->updateSearchIndexConfig();
				}
			],
			[
				'name' => 'notify-newprojects',
				'description' => 'Notifying the newprojects mailing list',
				'postInstall' => true,
				'callback' => function () {
					return $this->notifyNewProjects();
				}
			]
		];
	}

	protected function getTaskSkips(): array {
		return [ 'interwiki' ];
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
	 *
	 * @return Status
	 */
	private function populateSites() {
		$extDir = $this->getConfig()->get( MainConfigNames::ExtensionDirectory );
		// Populate sites table (this should be idempotent)
		// At least it's idempotent in the sense that it will give you the same fatal error every time
		return $this->runInstallScript(
			PopulateSitesTable::class,
			"$extDir/Wikibase/lib/maintenance/populateSitesTable.php",
			[
				'force-protocol' => 'https',
			],
		);
	}

	/**
	 * Set up Swift zones
	 *
	 * TODO: move to core
	 *
	 * @return Status
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
		return $this->runInstallScript(
			SetZoneAccess::class,
			"$extDir/WikimediaMaintenance/maintenance/filebackend/setZoneAccess.php",
			$options
		);
	}

	/**
	 * Set up ElasticSearch namespaces
	 *
	 * TODO: move to ElasticSearch install task or update
	 *
	 * @return Status
	 */
	private function updateSearchIndexConfig() {
		$extDir = $this->getConfig()->get( MainConfigNames::ExtensionDirectory );
		return $this->runInstallScript(
			UpdateSearchIndexConfig::class,
			"$extDir/CirrusSearch/maintenance/UpdateSearchIndexConfig.php",
			[ 'cluster' => 'all' ]
		);
	}

	/**
	 * Send an email to the newprojects mailing list
	 *
	 * @return Status
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

		return UserMailer::send( $to, $from, "New wiki: $wiki", $body );
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
	 * @param class-string<Maintenance> $class
	 * @param string $classFile
	 * @param array $options
	 * @return Status
	 */
	private function runInstallScript( $class, $classFile, $options ) {
		$wiki = WikiMap::getCurrentWikiId();
		$maint = $this->createChild( $class, $classFile );
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
			return Status::newGood();
		} else {
			return Status::newFatal( new RawMessage( "$baseName failed" ) );
		}
	}

}

// @codeCoverageIgnoreStart
$maintClass = AddWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
