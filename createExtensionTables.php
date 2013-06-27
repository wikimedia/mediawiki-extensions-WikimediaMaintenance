<?php

require_once( __DIR__ . '/WikimediaMaintenance.php' );

/**
 * Creates the necessary tables to install various extensions on a WMF wiki
 */
class CreateExtensionTables extends WikimediaMaintenance {
	function __construct() {
		parent::__construct();
		$this->addArg( 'extension', 'Which extension to install' );
	}

	function execute() {
		global $IP;
		$dbw = $this->getDB( DB_MASTER );
		$extension = $this->getArg();

		$files = array();
		$path = '';

		switch ( strtolower( $extension ) ) {
			case 'moodbar':
				$files = array(
					'MoodBar.sql',
					'moodbar_feedback_response.sql',
				);
				$path = "$IP/extensions/MoodBar/sql";
				break;

			case 'translate':
				$files = array(
					'revtag.sql',
					'translate_groupstats.sql',
					'translate_metadata.sql',
					'translate_sections.sql',
					'translate_groupreviews.sql',
					'translate_messageindex.sql',
					'translate_reviews.sql',
				);
				$path = "$IP/extensions/Translate/sql";
				break;

			case 'wikilove':
				$files = array(
					'WikiLoveImageLog.sql',
					'WikiLoveLog.sql',
				);
				$path = "$IP/extensions/WikiLove/patches";
				break;

			case 'educationprogram':
				$files = array( 'EducationProgram.sql' );
				$path = "$IP/extensions/EducationProgram/sql";
				break;
			case 'echo':
				$files = array( 'echo.sql' );
				$path = "$IP/extensions/Echo";
				break;

			default:
				$this->error( "This script is not configured to create tables for $extension\n", 1 );
		}

		$this->output( "Creating $extension tables..." );
		foreach( $files as $file ) {
			$dbw->sourceFile( "$path/$file" );
		}
		$this->output( "done!\n" );
	}
}

$maintClass = 'CreateExtensionTables';
require_once( DO_MAINTENANCE );

