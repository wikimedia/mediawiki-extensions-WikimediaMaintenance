<?php
/**
 * Why yes, this *is* another special-purpose Wikimedia maintenance script!
 * Should be fixed up and generalized.
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

class RenameWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Rename external storage dbs and leave a new one" );
		$this->addArg( 'olddb', 'Old DB name' );
		$this->addArg( 'newdb', 'New DB name' );
	}

	public function getDbType() {
		return Maintenance::DB_ADMIN;
	}

	public function execute() {
		global $wgDefaultExternalStore;

		# Setup
		$from = $this->getArg( 0 );
		$to = $this->getArg( 1 );

		$this->output( "Renaming core tables...\n" );
		$this->output( "Sleeping 5 seconds...\n" );
		sleep( 5 );

		$dbw = wfGetDB( DB_MASTER );
		foreach ( $dbw->tableNames() as $table ) {
			$dbw->query( "ALTER TABLE {$from}.{$table} RENAME TO {$to}.{$table}" );
		}

		$this->output( "done.\n" );

		$this->output( "Waiting for slaves...\n" );
		wfWaitForSlaves();
		$this->output( "done.\n" );

		$this->output( "Renaming blob tables in ES from $from to $to...\n" );
		$this->output( "Sleeping 5 seconds...\n" );
		sleep( 5 );

		# Initialise external storage
		if ( is_array( $wgDefaultExternalStore ) ) {
			$stores = $wgDefaultExternalStore;
		} elseif ( $wgDefaultExternalStore ) {
			$stores = [ $wgDefaultExternalStore ];
		} else {
			$stores = [];
		}

		if ( count( $stores ) ) {
			$this->output( "Initialising external storage...\n" );
			global $wgDBuser, $wgDBpassword, $wgExternalServers;
			foreach ( $stores as $storeURL ) {
				$m = [];
				if ( !preg_match( '!^DB://(.*)$!', $storeURL, $m ) ) {
					continue;
				}

				$cluster = $m[1];

				# Hack
				$wgExternalServers[$cluster][0]['user'] = $wgDBuser;
				$wgExternalServers[$cluster][0]['password'] = $wgDBpassword;

				$store = new ExternalStoreDB;
				$extdb = $store->getMaster( $cluster );
				$extdb->query( "SET default_storage_engine=InnoDB" );
				$extdb->query( "CREATE DATABASE IF NOT EXISTS {$to}" );
				$extdb->query( "ALTER TABLE {$from}.blobs RENAME TO {$to}.blobs" );

				$store = new ExternalStoreDB( [ 'wiki' => $from ] );
				$extdb = $store->getMaster( $cluster );
				$extdb->sourceFile( $this->getDir() . '/storage/blobs.sql' );
				$extdb->commit();
			}
		}
		$this->output( "done.\n" );
	}
}

$maintClass = RenameWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
