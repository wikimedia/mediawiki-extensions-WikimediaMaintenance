<?php
/*
 * Entry point for WikimediaMaintenance scripts :)
 */

// Detect $IP
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../..';
}

// Require base maintenance class
require_once( "$IP/maintenance/Maintenance.php" );

/**
 * @deprecated
 */
abstract class WikimediaMaintenance extends Maintenance {}
