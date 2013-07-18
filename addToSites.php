<?php

/**
 * Add wikidata and testwikidata to sites table
 *
 * @todo make this generic for adding any site
 *       and integrate with addWiki!
 */
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../..';
}

require_once ( "$IP/maintenance/Maintenance.php" );

class AddToSites extends Maintenance {

	function __construct() {
		parent::__construct();
		$this->mDescription = 'Adds Wikidata to the sites table';
	}

	function execute() {
		$siteStore = SiteSQLStore::newInstance();

		$sites = array();

		$sites[] = $this->getNewSite(
			'wikidatawiki', 'wikidata', 'en', 'http://www.wikidata.org'
		);

		$sites[] = $this->getNewSite(
			'testwikidatawiki', 'testwikidata', 'en', 'http://test.wikidata.org'
		);

		foreach( $sites as $site ) {
			$globalId = $site->getGlobalId();
			$this->clearSiteIfExists( $globalId );
			$siteStore->saveSite( $site );

			$this->output( "added $globalId to sites table\n" );
		}

		// clear caches
		$siteStore->reset();

		$this->output( "done\n" );
	}

	function clearSiteIfExists( $globalId ) {
		$dbw = wfGetDB( DB_MASTER );

		$site = $dbw->selectRow(
			'sites',
			array( 'site_global_key' ),
			array( 'site_global_key' => $globalId ),
			__METHOD__
		);

		if ( $site ) {
			$dbw->delete(
				'sites',
				array( 'site_global_key' => $globalId ),
				__METHOD__
			);

			$this->output( "cleared $globalId from sites table\n" );
		}
	}

	function getNewSite( $globalId, $group, $langCode, $baseUrl ) {
		$site = MediaWikiSite::newFromGlobalId( $globalId );
		$site->setGroup( $group );
		$site->setLanguageCode( $langCode );

		$extra = array( 'paths' =>
			array(
				'file_path' => "$baseUrl/w/$1",
				'page_path' => "$baseUrl/wiki/$1"
			)
		);

		$site->setExtraData( $extra );

		return $site;
	}

}

$maintClass = 'AddToSites';
require_once RUN_MAINTENANCE_IF_MAIN;
