<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * Some very old Flow notifications have event_extra fields with serialized DateTime objects
 * whose timezone is set to +00:00, which causes them to fail to unserialize in HHVM.
 * The serialization of Flow's UUID class has long since been fixed to not include DateTime objects
 * at all, so all we need to do is reserialize them in Zend (which doesn't barf on +00:00).
 */
class FixT159372 extends Maintenance {
	public function __construct() {
		$this->mDescription = 'Reserialize old Flow notifications for T159372';
		$this->setBatchSize( 50 );
	}

	public function execute() {
		$dbFactory = MWEchoDbFactory::newFromDefault();
		$dbw = $dbFactory->getEchoDb( DB_MASTER );
		$dbr = $dbFactory->getEchoDb( DB_REPLICA );
		$iterator = new BatchRowIterator(
			$dbr,
			'echo_event',
			'event_id',
			$this->mBatchSize
		);
		$iterator->addConditions( [
			"event_type LIKE 'flow%'",
			"event_extra LIKE '%O:8:\"DateTime\"%'"
		] );
		$iterator->setFetchColumns( [ 'event_id', 'event_extra' ] );

		$this->output( "Reserializing old Flow notifications...\n" );

		$processed = 0;
		foreach ( $iterator as $batch ) {
			foreach ( $batch as $row ) {
				try {
					$reserialized = serialize( unserialize( $row->event_extra ) );
					$dbw->update(
						'echo_event',
						[ 'event_extra' => $reserialized ],
						[ 'event_id' => $row->event_id ]
					);
					$processed += $dbw->affectedRows();
				} catch ( Exception $e ) {
					$this->output( "Failed to reserialize event_id {$row->event_id}" );
				}
			}
			$this->output( "Reserialized $processed events.\n" );
			$dbFactory->waitForSlaves();
		}
	}
}

$maintClass = 'FixT159372';
require_once RUN_MAINTENANCE_IF_MAIN;
