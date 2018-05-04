<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBReplicationWaitError;

class UnsuppressCrossWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Show number of jobs waiting in master database";
	}

	public function execute() {
		$userName = 'The Thing That Should Not Be'; // <- targer username

		$user = new CentralAuthUser( $userName, CentralAuthUser::READ_LATEST );
		if ( !$user->exists() ) {
			echo "Cannot unsuppress non-existent user {$userName}!\n";
			exit( 0 );
		}
		$userName = $user->getName(); // sanity
		$wikis = $user->listAttached(); // wikis with attached accounts
		$userQuery = User::getQueryInfo();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		foreach ( $wikis as $wiki ) {
			$lb = $lbFactory->getMainLB( $wiki );
			$dbw = $lb->getConnection( DB_MASTER, [], $wiki );
			# Get local ID like $user->localUserData( $wiki ) does
			$localUser = User::newFromRow( $dbw->selectField( $userQuery['tables'], $userQuery['fields'],
				[ 'user_name' => $userName ], __METHOD__, [], $userQuery['joins'] ) );

			$delUserBit = Revision::DELETED_USER;
			$revWhere = ActorMigration::newMigration()->getWhere( $dbw, 'rev_user', $localUser );
			$hiddenCount = 0;
			foreach ( $revWhere['orconds'] as $cond ) {
				$hiddenCount += $dbw->selectField(
					[ 'revision' ] + $revWhere['tables'],
					'COUNT(*)',
					[
						$cond,
						"rev_deleted & $delUserBit != 0"
					],
					__METHOD__,
					[],
					$revWhere['joins']
				);
			}
			echo "$hiddenCount edits have the username hidden on \"$wiki\"\n";
			# Unsuppress username on edits
			if ( $hiddenCount > 0 ) {
				echo "Unsuppressed edits of attached account (local id {$localUser->getId()}) on \"$wiki\"...";
				RevisionDeleteUser::unsuppressUserName( $userName, $localUser->getId(), $dbw );
				echo "done!\n\n";
			}
			$lb->reuseConnection( $dbw ); // not really needed
			# Don't lag too bad
			try {
				$lbFactory->waitForReplication( [ 'wiki' => $wiki ] );
			} catch ( DBReplicationWaitError $e ) {
				// Ignore
			}
		}
	}
}

$maintClass = 'UnsuppressCrossWiki';
require_once RUN_MAINTENANCE_IF_MAIN;
