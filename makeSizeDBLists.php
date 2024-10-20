<?php

/**
 * Builds database lists of wikis based on size
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

class MakeSizeDBLists extends Maintenance {

	private const DB_SMALL = 10000;
	private const DB_MEDIUM = 1000000;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Builds database lists of wikis based on size' );
	}

	public function execute() {
		global $wgConf;
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$small = [];
		$medium = [];
		$large = [];
		foreach ( $wgConf->getLocalDatabases() as $wiki ) {
			try {
				$lb = $lbFactory->getMainLB( $wiki );
				$db = $lb->getConnection( DB_PRIMARY, [], $wiki );
			} catch ( Exception $e ) {
				// Probably just wikitech etc, skip!
				continue;
			}
			$count = intval( $db->newSelectQueryBuilder()
				->select( 'ss_total_pages' )
				->from( 'site_stats' )
				->caller( __METHOD__ )
				->fetchField() );
			if ( $count < self::DB_SMALL ) {
				$small[] = $wiki;
			} elseif ( $count < self::DB_MEDIUM ) {
				$medium[] = $wiki;
			} else {
				$large[] = $wiki;
			}
		}

		$this->output( 'Small wiki count: ' . count( $small ) . "\n" );
		$this->writeFile( '/srv/mediawiki-staging/dblists/small.dblist', $small );
		$this->output( 'Medium wiki count: ' . count( $medium ) . "\n" );
		$this->writeFile( '/srv/mediawiki-staging/dblists/medium.dblist', $medium );
		$this->output( 'Large wiki count: ' . count( $large ) . "\n" );
		$this->writeFile( '/srv/mediawiki-staging/dblists/large.dblist', $large );
	}

	/**
	 * @param string $filename
	 * @param array $items
	 */
	private function writeFile( $filename, $items ) {
		$file = fopen( $filename, 'w' );
		fwrite( $file, implode( "\n", $items ) );
		fclose( $file );
	}
}

$maintClass = MakeSizeDBLists::class;
require_once RUN_MAINTENANCE_IF_MAIN;
