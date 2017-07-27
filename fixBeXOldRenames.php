<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * During SUL finalization, users on be_x_old wiki (and a few other
 * databases with _'s in them) were renamed to User:Foo~be_x_oldwiki,
 * which is an invalid username. This script takes a list of those
 * bad usernames, and renames them to User:Foo~be-x-oldwiki
 */
class FixBeXOldRenames extends Maintenance {
	public function __construct() {
		$this->mDescription = 'Fix broken be_x_oldwiki SUL finalization renames';
		$this->setBatchSize( 30 );
		$this->addOption( 'list', 'List of users to fix', true, true );
	}

	public function execute() {
		$list = $this->getOption( 'list' );
		$file = fopen( $list, 'r' );
		if ( $file === false ) {
			$this->output( "ERROR - Could not open file: $list" );
			exit( 1 );
		} else {
			$this->output( "Reading from $list\n" );
		}
		$count = 0;
		while ( $line = trim( fgets( $file ) ) ) {
			$this->output( "$line\n" );
			$this->rename( $line );
			$count++;
			if ( $count > $this->mBatchSize ) {
				$count = 0;
				$this->output( "Sleep for 5 and waiting for slaves..." );
				CentralAuthUser::waitForSlaves();
				wfWaitForSlaves();
				sleep( 5 );
				$this->output( "done.\n" );
			}
		}
		fclose( $file );
	}

	protected function rename( $oldname ) {
		$oldUser = User::newFromName( $oldname );
		$oldUser->mName = $oldname;
		$newUser = User::newFromName( str_replace( '_', '-', $oldname ), 'usable' );
		$maintScript = User::newFromName( 'Maintenance script' );
		$session = [
			'userId' => $maintScript->getId(),
			'ip' => '127.0.0.1',
			'sessionId' => '0',
			'headers' => [],
		];
		$data = [
			'movepages' => true,
			'suppressredirects' => true,
			'reason' => '[[m:Special:MyLanguage/Single User Login finalisation announcement|SUL finalization]]',
			'force' => true,
		];
		$globalRenameUser = new GlobalRenameUser(
			$maintScript,
			$oldUser,
			CentralAuthUser::getInstance( $oldUser ),
			$newUser,
			CentralAuthUser::getInstance( $newUser ),
			new GlobalRenameUserStatus( $newUser->getName() ),
			'JobQueueGroup::singleton',
			new GlobalRenameUserDatabaseUpdates(),
			new GlobalRenameUserLogger( $maintScript ),
			$session
		);
		$globalRenameUser->rename( $data );
	}
}

$maintClass = 'FixBeXOldRenames';
require_once RUN_MAINTENANCE_IF_MAIN;
