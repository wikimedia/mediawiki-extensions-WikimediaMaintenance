<?php
/**
 * @defgroup Wikimedia Wikimedia
 */

/**
 * Set a skin preference for a user. Mostly nice for running in a loop on
 * bunches of wikis
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
require_once __DIR__ . '/WikimediaMaintenance.php';

class ChangeSkinPref extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Set a skin for a user, usually monobook';
		$this->addArg( 'user', 'Which user to set the skin on' );
		$this->addOption( 'skin', 'Which skin to set (default monobook)', false, true );
	}

	public function execute() {
		$this->setSkin(
			$this->getArg(),
			$this->getOption( 'skin', 'monobook' )
		);
	}

	private function setSkin( $userName, $newSkin ) {
		$user = User::newFromName( $userName );
		$wiki = wfWikiID();
		if ( !$user || $user->getId() === 0 ) {
			$this->fatalError( "User $userName does not exist or is invalid." );
		}
		if ( !array_key_exists( $newSkin, Skin::getSkinNames() ) ) {
			$this->fatalError( "$newSkin is not a valid skin" );
		}
		$skin = $user->getOption( 'skin' );
		if ( $skin === $newSkin ) {
			$this->output( "{$userName}@{$wiki}: Skin already set to $newSkin; nothing to do.\n" );
			return;
		}
		$user->setOption( 'skin', $newSkin );
		$user->saveSettings();
		$this->output( "{$userName}@{$wiki}: Changed from $skin to $newSkin\n" );
	}
}

$maintClass = "ChangeSkinPref";
require_once RUN_MAINTENANCE_IF_MAIN;
