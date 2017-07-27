<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

class unsuppressCrossWiki extends Maintenance {
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
		foreach ( $wikis as $wiki ) {
			$lb = wfGetLB( $wiki );
			$dbw = $lb->getConnection( DB_MASTER, [], $wiki );
			# Get local ID like $user->localUserData( $wiki ) does
			$localUserId = $dbw->selectField( 'user', 'user_id',
				[ 'user_name' => $userName ], __METHOD__ );

			$delUserBit = Revision::DELETED_USER;
			$hiddenCount = $dbw->selectField( 'revision', 'COUNT(*)',
				[ 'rev_user' => $localUserId, "rev_deleted & $delUserBit != 0" ], __METHOD__ );
			echo "$hiddenCount edits have the username hidden on \"$wiki\"\n";
			# Unsuppress username on edits
			if ( $hiddenCount > 0 ) {
				echo "Unsuppressed edits of attached account (local id $localUserId) on \"$wiki\"...";
				RevisionDeleteUser::unsuppressUserName( $userName, $localUserId, $dbw );
				echo "done!\n\n";
			}
			$lb->reuseConnection( $dbw ); // not really needed
			# Don't lag too bad
			wfWaitForSlaves( 5 );
		}
	}
}

$maintClass = 'unsuppressCrossWiki';
require_once DO_MAINTENANCE;
