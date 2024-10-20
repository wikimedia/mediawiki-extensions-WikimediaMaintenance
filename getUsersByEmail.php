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
 * @author <jbond at wikimedia dot org>
 * @license GPL-2.0-or-later
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\Maintenance\Maintenance;

/**
 * Maintenance script to gather data associated with a specific email address
 *
 * This script will return a json blob containing the usernames and
 * authenticated date for all users matching a specific email address.
 *
 * The authenticated date indicates the date the account was
 * authenticated/verified via email.  If this value is empty/null it indicates
 * the user never completed the verification process
 *
 * @ingroup Maintenance
 */
class GetUsersByEmail extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Return a json blob containing the usernames and authenticated date '
			. 'for all users matching a specific email address' );
		$this->addOption( 'email', 'E-mail address', true, true );
	}

	public function execute() {
		$email = trim( $this->getOption( 'email' ) );
		$dbr = $this->getDB( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'user_name', 'user_email', 'user_email_authenticated' ] )
			->from( 'user' )
			->where( [ 'user_email' => $email ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		if ( $res->numRows() === 0 ) {
			$this->fatalError( $email . " was not found on the wiki" );
		}

		$json_data = [];
		foreach ( $res as $row ) {
			$json_data[] = [
				"username" => $row->user_name,
				"email" => $row->user_email,
				"email_authenticated_date" => $row->user_email_authenticated,
			];
		}

		$this->output( json_encode( $json_data, JSON_PRETTY_PRINT ) . "\n" );
	}
}

$maintClass = GetUsersByEmail::class;
require_once RUN_MAINTENANCE_IF_MAIN;
