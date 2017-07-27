<?php

/**
 * Builds database lists of wikis based on size
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

class MakeSizeDBLists extends Maintenance {

	const DB_SMALL = 10000;
	const DB_MEDIUM = 1000000;

	function __construct() {
		parent::__construct();
		$this->mDescription = 'Builds database lists of wikis based on size';
	}

	function execute() {
		global $wgConf;
		$small = [];
		$medium = [];
		$large = [];
		foreach ( $wgConf->getLocalDatabases() as $wiki ) {
			try {
				$lb = wfGetLB( $wiki );
				$db = $lb->getConnection( DB_MASTER, [], $wiki );
			} catch ( Exception $e ) {
				// Probably just wikitech etc, skip!
				continue;
			}
			$count = intval( $db->selectField( 'site_stats', 'ss_total_pages', '', __METHOD__ ) );
			if ( $count < self::DB_SMALL ) {
				$small[] = $wiki;
			} elseif ( $count < self::DB_MEDIUM ) {
				$medium[] = $wiki;
			} else {
				$large[] = $wiki;
			}
			$lb->reuseConnection( $db );
		}

		$this->output( 'Small wiki count: ' . count( $small ) . "\n" );
		$this->writeFile( '/srv/mediawiki-staging/dblists/small.dblist', $small );
		$this->output( 'Medium wiki count: ' . count( $medium ) . "\n" );
		$this->writeFile( '/srv/mediawiki-staging/dblists/medium.dblist', $medium );
		$this->output( 'Large wiki count: ' . count( $large ) . "\n" );
		$this->writeFile( '/srv/mediawiki-staging/dblists/large.dblist', $large );
	}

	/**
	 * @param $filename string
	 * @param $items array
	 */
	private function writeFile( $filename, $items ) {
		$file = fopen( $filename, 'w' );
		fwrite( $file, implode( "\n", $items ) );
		fclose( $file );
	}
}

$maintClass = 'MakeSizeDBLists';
require_once DO_MAINTENANCE;
