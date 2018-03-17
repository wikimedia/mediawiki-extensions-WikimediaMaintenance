<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

class FixUsabilityPrefs extends Maintenance {
	function __construct() {
		parent::__construct();
	}

	function execute() {
		$dbw = wfGetDB( DB_MASTER );

		echo "Fixing usebetatoolbar\n";

		$batchSize = 100;
		$allIds = [];
		while ( true ) {
			$this->beginTransaction( $dbw, __METHOD__ );
			$res = $dbw->select( 'user_properties', [ 'up_user' ],
				[ 'up_property' => 'usebetatoolbar', 'up_value' => '' ],
				__METHOD__,
				[ 'LIMIT' => $batchSize, 'FOR UPDATE' ] );
			if ( !$res->numRows() ) {
				$this->commitTransaction( $dbw, __METHOD__ );
				break;
			}

			$ids = [];
			foreach ( $res as $row ) {
				$ids[] = $row->up_user;
			}
			$dbw->update( 'user_properties', [ 'up_value' => 0 ],
				[ 'up_property' => 'usebetatoolbar', 'up_user' => $ids ],
				__METHOD__ );
			$this->commitTransaction( $dbw, __METHOD__ );
			$allIds = array_merge( $allIds, $ids );
			wfWaitForSlaves( 10 );
		}

		echo "Fixing wikieditor-*\n";

		$likeWikieditor = $dbw->buildLike( 'wikieditor-', $dbw->anyString() );
		while ( true ) {
			$this->beginTransaction( $dbw, __METHOD__ );
			$res = $dbw->select( 'user_properties', [ 'DISTINCT up_user' ],
				[ "up_property $likeWikieditor" ],
				__METHOD__,
				[ 'LIMIT' => $batchSize, 'FOR UPDATE' ] );
			if ( !$res->numRows() ) {
				$this->commitTransaction( $dbw, __METHOD__ );
				break;
			}

			$ids = [];
			foreach ( $res as $row ) {
				$ids[] = $row->up_user;
			}
			$dbw->delete( 'user_properties',
				[ "up_property $likeWikieditor", 'up_user' => $ids ],
				__METHOD__ );
			$this->commitTransaction( $dbw, __METHOD__ );
			$allIds = array_merge( $allIds, $ids );
			wfWaitForSlaves( 10 );
		}

		$allIds = array_unique( $allIds );

		echo "Fixing usenavigabletoc\n";

		while ( true ) {
			$this->beginTransaction( $dbw, __METHOD__ );
			$res = $dbw->select( 'user_properties', [ 'DISTINCT up_user' ],
				[ "up_property" => "usenavigabletoc" ],
				__METHOD__,
				[ 'LIMIT' => $batchSize, 'FOR UPDATE' ] );
			if ( !$res->numRows() ) {
				$this->commitTransaction( $dbw, __METHOD__ );
				break;
			}

			$ids = [];
			foreach ( $res as $row ) {
				$ids[] = $row->up_user;
			}
			$dbw->delete( 'user_properties',
				[ "up_property" => "usenavigabletoc", 'up_user' => $ids ],
				__METHOD__ );
			$this->commitTransaction( $dbw, __METHOD__ );
			$allIds = array_merge( $allIds, $ids );
			wfWaitForSlaves( 10 );
		}

		echo "Invalidating user cache\n";
		$i = 0;
		foreach ( $allIds as $id ) {
			$user = User::newFromId( $id );
			if ( !$user->isLoggedIn() ) {
				continue;
			}
			$this->beginTransaction( $dbw, __METHOD__ );
			$user->invalidateCache();
			$this->commitTransaction( $dbw, __METHOD__ );
			$i++;
			if ( $i % 1000 == 0 ) {
				wfWaitForSlaves( 10 );
			}
		}

		echo "Done\n";
	}
}

$maintClass = 'FixUsabilityPrefs';
require_once RUN_MAINTENANCE_IF_MAIN;
