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

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\Maintenance\Maintenance;

/**
 * Usage:
 *  $ mwscript getWikisBySetting.php --setting wgExampleUrl --value '/example'
 *  $ mwscript getWikisBySetting.php --setting wgExampleUrl --not --value '/example'
 *  $ mwscript getWikisBySetting.php -s wgExampleUrl -! -v '/example'
 *
 *  $ mwscript getWikisBySetting.php --setting wgUseExample
 *  $ mwscript getWikisBySetting.php --setting wgUseExample --not
 *  $ mwscript getWikisBySetting.php -! -s wmgUseExample
 */
class GetWikisBySetting extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Returns a list of wikis where a given setting is set to a given value.' );
		$this->addOption( 'setting', 'Setting name', true, true, 's' );
		$this->addOption( 'value',
			'Value to check against, if omitted the script will check for boolean true as value',
			false, true, 'v'
		);
		$this->addOption( 'not', 'Inverse comparison', false, false, '!' );
	}

	public function execute() {
		global $wgConf;

		$setting = $this->getOption( 'setting' );
		$expected = $this->getOption( 'value', true );
		$invert = $this->hasOption( 'not' );

		$wgConf->loadFullData();
		foreach ( $wgConf->getLocalDatabases() as $wiki ) {
			$value = $wgConf->get( $setting, $wiki );
			$match = $value === $expected;
			if ( $invert ) {
				$match = !$match;
			}
			if ( $match ) {
				$this->output( "$wiki\n" );
			}
		}
	}
}

$maintClass = GetWikisBySetting::class;
require_once RUN_MAINTENANCE_IF_MAIN;
