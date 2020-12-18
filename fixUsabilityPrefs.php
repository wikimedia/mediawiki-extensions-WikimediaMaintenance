<?php

use MediaWiki\MediaWikiServices;

require_once __DIR__ . '/WikimediaMaintenance.php';

class FixUsabilityPrefs extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		$dbw = wfGetDB( DB_MASTER );
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

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
			$dbw->delete( 'user_properties',
				[ 'up_property' => 'usebetatoolbar', 'up_user' => $ids ],
				__METHOD__ );
			$this->commitTransaction( $dbw, __METHOD__ );
			$allIds = array_merge( $allIds, $ids );
			$lbFactory->waitForReplication( [ 'ifWritesSince' => 10 ] );
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
			$lbFactory->waitForReplication( [ 'ifWritesSince' => 10 ] );
		}

		$allIds = array_unique( $allIds );

		echo "Invalidating user cache\n";
		$i = 0;
		foreach ( $allIds as $id ) {
			$user = User::newFromId( $id );
			if ( !$user->isRegistered() ) {
				continue;
			}
			$this->beginTransaction( $dbw, __METHOD__ );
			$user->invalidateCache();
			$this->commitTransaction( $dbw, __METHOD__ );
			$i++;
			if ( $i % 1000 == 0 ) {
				$lbFactory->waitForReplication( [ 'ifWritesSince' => 10 ] );
			}
		}

		echo "Done\n";
	}
}

$maintClass = FixUsabilityPrefs::class;
require_once RUN_MAINTENANCE_IF_MAIN;
