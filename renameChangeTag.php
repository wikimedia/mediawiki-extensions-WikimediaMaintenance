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
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;

/**
 * Maintenance script that renames a change tag.
 *
 * This script does not rename tag log entries!
 *
 * @since 1.40
 */
class RenameChangeTag extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( "Renames a change tag" );

		$this->addArg( 'oldname', 'Name of the tag that should be updated', true );
		$this->addArg( 'newname', 'The name it should be updated to', true );
	}

	public function execute() {
		$oldname = $this->getArg( 0 );
		$newname = $this->getArg( 1 );
		$dbw = $this->getDB( DB_PRIMARY );

		$this->output( "Rename tag {$oldname} to {$newname} ...\n" );
		$this->output( "Updating change_tag_def and abuse_filter_action ..." );

		$this->beginTransaction( $dbw, __METHOD__ );

		$dbw->update(
			'change_tag_def',
			[ 'ctd_name' => $newname ],
			[ 'ctd_name' => $oldname ],
			__METHOD__
		);

		$dbw->update(
			'abuse_filter_action',
			[ 'afa_parameters' => $newname ],
			[
				'afa_parameters' => $oldname,
				'afa_consequence' => 'tag'
			],
			__METHOD__
		);

		$this->commitTransaction( $dbw, __METHOD__ );

		$this->output( "done\n" );
		$this->output( "Clearing ChangeTags cache..." );

		// clear cache, abuse filter also clears core's cache
		AbuseFilterServices::getChangeTagsManager()->purgeTagCache();

		$this->output( "done\n" );
	}
}

$maintClass = RenameChangeTag::class;
require_once RUN_MAINTENANCE_IF_MAIN;
