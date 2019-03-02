<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBReplicationWaitError;

class UnsuppressCrossWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Globally unsuppress a user name";
		$this->addOption( 'user', 'The username to operate on', false, true );
		$this->addOption( 'userid', 'The user id to operate on', false, true );
	}

	public function execute() {
		if ( $this->hasOption( 'user' ) ) {
			$user = CentralAuthUser::getMasterInstanceByName( $this->getOption( 'user' ) );
		} elseif ( $this->hasOption( 'userid' ) ) {
			$user = CentralAuthUser::newMasterInstanceFromId( $this->getOption( 'userid' ) );
		} else {
			$this->fatalError( "A \"user\" or \"userid\" must be set to unsuppress for" );
		}

		if ( !$user || !$user->exists() ) {
			$user = $this->hasOption( 'user' ) ? $this->getOption( 'user' ) : $this->getOption( 'userid' );
			echo "Cannot unsuppress non-existent user {$user}!\n";
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
			$localUser = User::newFromRow( $dbw->selectRow( $userQuery['tables'], $userQuery['fields'],
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

$maintClass = UnsuppressCrossWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
