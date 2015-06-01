<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 * @ingroup Wikimedia
 */
require_once( __DIR__ . '/WikimediaMaintenance.php' );

/**
 * Creates the necessary tables to install various extensions on a WMF wiki
 */
class CreateExtensionTables extends WikimediaMaintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = 'Creates database tables for specific MediaWiki Extensions';
		$this->addArg( 'extension', 'Which extension to install' );
	}

	function execute() {
		global $IP, $wgEchoCluster;
		$dbw = $this->getDB( DB_MASTER );
		$extension = $this->getArg( 0 );

		$files = array();
		$path = '';

		switch ( strtolower( $extension ) ) {
			case 'echo':
				if ( $wgEchoCluster !== false ) {
					$this->error( "Cannot create Echo tables on $wgEchoCluster using this script.", 1 );
				}
				$files = array( 'echo.sql' );
				$path = "$IP/extensions/Echo";
				break;

			case 'educationprogram':
				$files = array( 'EducationProgram.sql' );
				$path = "$IP/extensions/EducationProgram/sql";
				break;

			case 'flaggedrevs':
				$files = array( 'FlaggedRevs.sql' );
				$path = "$IP/extensions/FlaggedRevs/backend/schema/mysql";
				break;

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
				$files = array( 'WikiLoveLog.sql' );
				$path = "$IP/extensions/WikiLove/patches";
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

