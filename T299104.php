<?php
/**
 * Script to update users invalid skin preferences after skin splitting.
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

use MediaWiki\MediaWikiServices;

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * Maintenance script that updates invalid user skin preferences after skin splitting.
 *
 * For users with VectorSkinVersion set to 2, this script will update their 'skin'
 * property from 'vector' to 'vector-2022' if a 'skin' row for the user exists and
 * is set to 'vector'. For users with VectorSkinVersion set to 2 but do not have
 * a 'skin' row (excludes 'skin' set to 'monobook', etc), it will insert a new row
 * for the user with 'skin' set to 'vector-2022'.
 * This script will also update global preferences for relevant users per rules
 * above if the global option is used. The global_preferences table in the
 * CentralAuth database is almost identical to the user_properties table.
 * Note that running the script without any options will default to a dry run.
 * You must append the `--commit` option to actually run the database updates.
 *
 * @par Usage example:
 * @code
 * php extensions/WikimediaMaintenance/T299104.php --commit --global --insert
 * @endcode
 *
 * @see bug T299104
 */
class UpdateUserSkinPreferences extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Updates users skin preferences to match vector skin version.' );
		$this->addOption( 'global', 'Update user skin preferences globally' );
		$this->addOption( 'commit', 'Actually update user skin preferences' );
		$this->addOption( 'insert', 'Run the inserts in addition to the updates' );
		$this->setBatchSize( 50 );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$global = $this->hasOption( 'global' );
		// Set up the databases
		if ( $global ) {
			if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) &&
				ExtensionRegistry::getInstance()->isLoaded( 'GlobalPreferences' )
			) {
				$dbw = MediaWikiServices::getInstance()->get( 'CentralAuth.CentralAuthDatabaseManager' )
					->getCentralDB( DB_PRIMARY );
				$dbr = MediaWikiServices::getInstance()->get( 'CentralAuth.CentralAuthDatabaseManager' )
					->getCentralDB( DB_REPLICA );
			} else {
				$this->output( "This script cannot be run globally because the required extensions are missing:"
					. " CentralAuth and GlobalPreferences." . PHP_EOL );
				return false;
			}
		} else {
			$dbw = $this->getDB( DB_PRIMARY );
			$dbr = $this->getDB( DB_REPLICA, [ 'vslow' ] );
		}

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		// Set up the table and columns for the queries.
		if ( $global ) {
			$table = 'global_preferences';
			$user = 'gp_user';
			$property = 'gp_property';
			$propertyValue = 'gp_value';
			$preferenceWord = 'global';
		} else {
			$table = 'user_properties';
			$user = 'up_user';
			$property = 'up_property';
			$propertyValue = 'up_value';
			$preferenceWord = 'user';
		}

		$commit = $this->hasOption( 'commit' );
		$dryRun = !$commit;
		$updateWord = $dryRun ? 'Would update' : 'Updated';
		$insertWord = $dryRun ? 'Would insert' : 'Inserted';
		$doInsert = $this->hasOption( 'insert' );

		if ( !$dryRun ) {
			$this->warn( 'skin', 'vector', 'vector-2022' );
		}

		// Limit the pool of relevant user ids with those who have VectorSkinVersion = 2.
		$iterator = new BatchRowIterator(
			$dbr,
			$table,
			$user,
			$this->getBatchSize()
		);
		$iterator->setFetchColumns( [ $user ] );
		$iterator->addConditions( [
			$property => 'VectorSkinVersion',
			$propertyValue => '2',
		] );
		$iterator->setCaller( __METHOD__ );

		$userIdsHasPropertyVectorSkinVersionAndSkin = $updatesFoundUserIds = $insertsFoundUserIds = [];
		$start = microtime( true );

		foreach ( $iterator as $batch ) {
			$userIdsWithPropertyVectorSkinVersion = [];
			foreach ( $batch as $row ) {
				$userIdsWithPropertyVectorSkinVersion[] = $row->$user;
				// Get all the user ids that have VectorSkinVersion = 2 and have a 'skin' row.
				$hasPropertyVectorSkinVersionAndSkin = $dbr->select(
					$table,
					[ $user . ' AS userId', $propertyValue ],
					[
						$user => $row->$user,
						$property => 'skin'
					],
					__METHOD__
				);

				foreach ( $hasPropertyVectorSkinVersionAndSkin as $userIdHasPropertyVectorSkinVersionAndSkin ) {
					$userIdsHasPropertyVectorSkinVersionAndSkin[] = $userIdHasPropertyVectorSkinVersionAndSkin->userId;
					// Find the user ids that have VectorSkinVersion = 2 and skin = vector.
					if ( $userIdHasPropertyVectorSkinVersionAndSkin->$propertyValue === 'vector' ) {
						if ( !$dryRun ) {
							// Update the user skin preference to vector-2022.
							$dbw->update(
								$table,
								[ $propertyValue => 'vector-2022' ],
								[
									$user => $userIdHasPropertyVectorSkinVersionAndSkin->userId,
									$property => 'skin',
									$propertyValue => 'vector',
								],
								__METHOD__
							);
							$lbFactory->waitForReplication();
						}
						$updatesFoundUserIds[] = $userIdHasPropertyVectorSkinVersionAndSkin->userId;
						$this->output( "$updateWord 'skin' for user id"
							. " $userIdHasPropertyVectorSkinVersionAndSkin->userId from 'vector' to 'vector-2022'.\n" );
					}
				}
			}

			// Track the user ids from the batch that have VectorSkinVersion = 2 but do no have a 'skin' row.
			$userIdsWithPropertyVectorSkinVersion2MissingPropertySkin = array_diff(
				$userIdsWithPropertyVectorSkinVersion,
				$userIdsHasPropertyVectorSkinVersionAndSkin
			);

			foreach ( $userIdsWithPropertyVectorSkinVersion2MissingPropertySkin as $userIdMissingPropertySkin ) {
				if ( !$dryRun && $doInsert ) {
					// Insert the missing user skin preference to vector-2022.
					$dbw->insert(
						$table,
						[
							$user => $userIdMissingPropertySkin,
							$property => 'skin',
							$propertyValue => 'vector-2022',
						],
						__METHOD__
					);
					$lbFactory->waitForReplication();
				}
				$insertsFoundUserIds[] = $userIdMissingPropertySkin;
				if ( $doInsert ) {
					$this->output( "$insertWord row for user id $userIdMissingPropertySkin with 'skin' set to"
						. " 'vector-2022'.\n" );
				}
			}
		}

		$updatesFound = count( $updatesFoundUserIds );
		$insertsFound = count( $insertsFoundUserIds );

		$this->output( "\ntook: " . (int)( microtime( true ) - $start ) . "\n" );
		$this->output( "$updateWord $preferenceWord preferences affecting $updatesFound rows" . PHP_EOL );
		if ( $doInsert ) {
			$this->output( "$insertWord $preferenceWord preferences affecting $insertsFound rows" . PHP_EOL );
		} else {
			$this->output( "No rows inserted. Use --insert option if this is needed." . PHP_EOL );
		}
		return true;
	}

	/**
	 * The warning message and countdown
	 *
	 * @param string $option
	 * @param string $old
	 * @param string $new
	 */
	private function warn( $option, $old, $new ) {
		if ( $this->hasOption( 'nowarn' ) ) {
			return;
		}

		$this->output( <<<WARN
The script is about to change the preferences for all relevant users in the database.
Users with option '$option' set to '$old' will be updated to '$new'.

Abort with control-c in the next five seconds....
WARN
		);
		$this->countDown( 5 );
	}
}

$maintClass = UpdateUserSkinPreferences::class;
require_once RUN_MAINTENANCE_IF_MAIN;
