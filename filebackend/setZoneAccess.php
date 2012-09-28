<?php
require_once( __DIR__ . '/Maintenance.php' );

class SetZoneAccess extends Maintenance {
	public function construct() {
		parent::__construct();
		$this->addOption( 'backend', 'Name of the file backend', true, true );
		$this->addOption( 'private', 'Make all containers private' );
	}

	public function execute() {
		$backend = FileBackendGroup::singleton()->get( $this->getOption( 'backend' ) );
		foreach ( array( 'public', 'thumb', 'temp', 'deleted' ) as $zone ) { // all zones
			$dir = $backend->getRootStoragePath() . "/local-$zone";
			$secure = ( $zone === 'deleted' || $this->hasOption( 'private' ) )
				? array( 'noAccess' => true, 'noListing' => true )
				: array();
			// Create zone if it doesn't exist...
			$this->output( "Making sure $dir exists..." );
			$status = $backend->prepare( array( 'dir' => $dir ) + $secure );
			// Make sure zone has the right ACLs...
			if ( count( $secure ) ) { // private
				$this->output( "making '$zone' private..." );
				$status->merge( $backend->secure( array( 'dir' => $dir ) + $secure ) );
			} else { // public
				$this->output( "making '$zone' public..." );
				$status->merge( $backend->publish( array( 'dir' => $dir, 'access' => true ) ) );
			}
			$this->output( "done.\n" );
			if ( !$status->isOK() ) {
				print_r( array_merge( $status->getErrorsArray(), $status->getWarningsArray() ) );
			}
		}
	}
}
$maintClass = 'SetZoneAccess';
require_once( RUN_MAINTENANCE_IF_MAIN );
