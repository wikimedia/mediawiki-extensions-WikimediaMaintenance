<?php
/**
 * @defgroup Wikimedia Wikimedia
 */

/**
 * Cleanup ancient skin preferences
 *
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

class CleanupSkinPrefs extends WikimediaMaintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Cleanup old skin preferences from user_properties';
		$this->addOption( 'count', 'Just count how many bogus skin entries there are', false, false );
	}

	public function execute() {
		global $wgDefaultSkin, $wmfVersionNumber;
		if ( !$wmfVersionNumber ) { // set in CommonSettings.php
			$this->error( '$wmfVersionNumber is not set, please use MWScript.php wrapper.', true );
		}

		# Explicit skins we want to remap. All other bogus skins fall back to
		# Vector, since that's what they've done for ages.
		$cleanupMap = array(
			0 => $wgDefaultSkin,
			1 => 'nostalgia',
			2 => 'cologneblue',
		);

		# Current skins
		$currentSkins = array_keys( Skin::getSkinNames() );

		$dbr = wfGetDB( DB_SLAVE );
		$dbw = wfGetDB( DB_MASTER );

		$countOnly = $this->getOption( 'count', false );

		$res = (int)$dbr->selectField( 'user_properties', 'COUNT(*) as count',
			array( 'up_property' => 'skin', 'up_value' => array_keys( $cleanupMap ) ), __METHOD__ );
		$this->output( "$res users with old integer-style skin preferences\n" );
		if( !$countOnly && $count > 0 ) {
			$this->output( "Updating..." );
			foreach( $cleanupMap as $old => $new ) {
				$dbw->update( 'user_properties', array( 'up_value' => $new ),
					array( 'up_property' => 'skin', 'up_value' => $old ), __METHOD__ );
				wfWaitForSlaves( 5 );
			}
			$this->output( "done.\n" );
		}

		$res = (int)$dbr->selectField( 'user_properties', 'COUNT(*) as count',
			array( 'up_property' => 'skin', 'up_value NOT IN (' . $dbr->makeList( $currentSkins ) . ')' ), __METHOD__ );
		$this->output( "$res users with bogus skin properties\n" );
		if( !$countOnly && $count > 0 ) {
			$this->output( "Updating..." );
			$dbw->delete( 'user_properties',
				array( 'up_property' => 'skin', 'up_value NOT IN (' . $dbw->makeList( $currentSkins ) . ')' ), __METHOD__ );
			$this->output( "done.\n" );
		}
	}
}

$maintClass = "CleanupSkinPrefs";
require_once( RUN_MAINTENANCE_IF_MAIN );
