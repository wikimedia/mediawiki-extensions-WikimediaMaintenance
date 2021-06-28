<?php

use MediaWiki\MediaWikiServices;

require_once __DIR__ . '/WikimediaMaintenance.php';

class FixUsabilityPrefs2 extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$dbw = wfGetDB( DB_PRIMARY );

		echo "Fixing usebetatoolbar\n";

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$batchSize = $this->getBatchSize();
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
			$lbFactory->waitForReplication( [ 'ifWritesSince' => 10 ] );
		}

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

$maintClass = FixUsabilityPrefs2::class;
require_once RUN_MAINTENANCE_IF_MAIN;
