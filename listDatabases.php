<?php
require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\Maintenance\Maintenance;

class ListDatabases extends Maintenance {
	public function __construct() {
		$this->addDescription( 'Prints a list of databases' );
		parent::__construct();
	}

	public function execute() {
		global $wgLocalDatabases;
		foreach ( $wgLocalDatabases as $db ) {
			$this->output( "$db\n" );
		}
	}

}

$maintClass = ListDatabases::class;
require_once RUN_MAINTENANCE_IF_MAIN;
