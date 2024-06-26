<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\ActorMigration;
use MediaWiki\User\User;
use Wikimedia\Rdbms\DBReplicationWaitError;

class UnsuppressCrossWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Globally unsuppress a user name" );
		$this->addOption( 'user', 'The username to operate on', true, true );
	}

	public function execute() {
		$user = CentralAuthUser::getPrimaryInstanceByName( $this->getOption( 'user' ) );

		if ( !$user->exists() ) {
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
			$dbw = $lb->getConnection( DB_PRIMARY, [], $wiki );
			# Get local ID like $user->localUserData( $wiki ) does
			$localUser = User::newFromRow( $dbw->newSelectQueryBuilder()
				->queryInfo( $userQuery )
				->where( [ 'user_name' => $userName ] )
				->caller( __METHOD__ )
				->fetchRow()
			);

			$delUserBit = RevisionRecord::DELETED_USER;
			$revWhere = ActorMigration::newMigration()->getWhere( $dbw, 'rev_user', $localUser );
			$hiddenCount = 0;
			foreach ( $revWhere['orconds'] as $cond ) {
				$hiddenCount += $dbw->newSelectQueryBuilder()
					->select( 'COUNT(*)' )
					->from( 'revision' )
					->tables( $revWhere['tables'] )
					->where( [
						$cond,
						"rev_deleted & $delUserBit != 0"
					] )
					->joinConds( $revWhere['joins'] )
					->caller( __METHOD__ )
					->fetchField();
			}
			echo "$hiddenCount edits have the username hidden on \"$wiki\"\n";
			# Unsuppress username on edits
			if ( $hiddenCount > 0 ) {
				echo "Unsuppressed edits of attached account (local id {$localUser->getId()}) on \"$wiki\"...";
				RevisionDeleteUser::unsuppressUserName( $userName, $localUser->getId(), $dbw );
				echo "done!\n\n";
			}
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
