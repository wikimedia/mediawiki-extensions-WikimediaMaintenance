<?php

require_once( __DIR__ . '/WikimediaMaintenance.php' );

/**
 * Creates the necessary tables to install Translate on a WMF wiki
 */
class CreateTranslateTables extends WikimediaMaintenance {
	function __construct() {
		parent::__construct();
	}

	function execute() {
		global $IP;
		$dbw = $this->getDB( DB_MASTER );
		$tables = array(
			'revtag.sql',
			'translate_groupstats.sql',
			'translate_metadata.sql',
			'translate_sections.sql',
			'translate_groupreviews.sql',
			'translate_messageindex.sql',
			'translate_reviews.sql',
		);
		$this->output( "Creating Translate tables..." );
		foreach( $tables as $table ) {
			$dbw->sourceFile( "$IP/extensions/Translate/sql/$table" );
		}
		$this->output( "done!\n" );
	}
}

$maintClass = 'CreateTranslateTables';
require_once( DO_MAINTENANCE );