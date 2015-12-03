<?php

require_once __DIR__ .'/WikimediaCommandLine.inc';

function fixBug41778() {
	$dbw = wfGetDB( DB_MASTER );

	$maxLength = $dbw->selectField(
		'information_schema.columns',
		'CHARACTER_MAXIMUM_LENGTH',
		array(
			'table_schema' => $dbw->getDBname(),
			'table_name' => 'ipblocks',
			'column_name' => 'ipb_range_start'
		),
		__METHOD__ );

	print "Existing field length: $maxLength\n";

	if ( $maxLength < 40 ) {
		print "Modifying columns and indexes.\n";
		$dbw->query(
			'ALTER TABLE ipblocks ' .
			'DROP INDEX ipb_range, ' .
			'MODIFY ipb_range_start tinyblob NOT NULL, ' .
			'MODIFY ipb_range_end tinyblob NOT NULL, ' .
			'ADD INDEX ipb_range (ipb_range_start(20), ipb_range_end(20))' );
	} else {
		$res = $dbw->query( 'SHOW CREATE TABLE ipblocks' );
		$row = $res->fetchRow();
		if ( !preg_match( '/KEY.*`ipb_range_start`(\((\d+)\))?/', $row['Create Table'], $m ) ) {
			print "Unable to interpret SHOW CREATE TABLE output\n";
			exit( 1 );
		}
		if ( isset( $m[2] ) ) {
			$length = $m[2];
		} else {
			$length = 0;
		}
		print "Existing index length: $length\n";

		if ( !isset( $m[2] ) || intval( $m[2] ) < 20 ) {
			assertCanAlter();
			print "Modifying indexes.\n";
			$dbw->query(
				'ALTER TABLE ipblocks ' .
				'DROP INDEX ipb_range, ' .
				'ADD INDEX ipb_range (ipb_range_start(20), ipb_range_end(20))' );
		}
	}

	wfWaitForSlaves();

	print "Regenerating field values\n";
	$res = $dbw->select( 'ipblocks', '*', array( 'ipb_range_start LIKE \'v6-%\'' ), __METHOD__ );
	foreach ( $res as $i => $row ) {
		list( $start, $end ) = IP::parseRange( $row->ipb_address );
		if ( substr( $start, 0, 3 ) !== 'v6-' || substr( $end, 0, 3 ) !== 'v6-' ) {
			print "Invalid address: {$row->ipb_address}\n";
			continue;
		}
		$dbw->update( 'ipblocks',
			/* SET */ array(
				'ipb_range_start' => $start,
				'ipb_range_end' => $end,
			),
			/* WHERE */ array(
				'ipb_id' => $row->ipb_id,
			)
		);
		if ( $i % 100 === 0 ) {
			wfWaitForSlaves();
		}
	}
}

function assertCanAlter() {
	$count = wfGetDB( DB_SLAVE )->selectField( 'ipblocks', 'count(*)', false, __METHOD__ );
	if ( $count > 1000000 ) {
		print "Table is too large for this script\n";
		exit( 1 );
	}
}

fixBug41778();
