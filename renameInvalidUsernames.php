<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * Rename users whose usernames are now invalid after
 * various MW changes, updates to interwiki map, etc.
 *
 * At the same time, convert them to a global account if necessary.
 * Unattached accounts will be renamed to 'Invalid username $userId~$wiki'
 * Global accounts will be renamed to 'Invalid username $globalUserId'
 * @see bug T5507
 */
class RenameInvalidUsernames extends Maintenance {
	public function __construct() {
		$this->mDescription = 'Rename invalid usernames to a generic one based on their user id';
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
			// xxwiki	#####
			$exp = explode( "\t", $line );
			$this->rename( $exp[1], $exp[0] );
			$count++;
			if ( $count > $this->mBatchSize ) {
				$count = 0;
				$this->output( "Sleep for 5 and waiting for slaves...\n" );
				CentralAuthUser::waitForSlaves();
				wfWaitForSlaves();
				sleep( 5 );
				$this->output( "done.\n" );
				$count = $this->getCurrentRenameCount();
				while ( $count > 15 ) {
					$this->output( "There are currently $count renames queued, pausing...\n" );
					sleep( 5 );
					$count = $this->getCurrentRenameCount();
				}
			}
		}
		fclose( $file );

	}

	protected function rename( $userId, $wiki ) {
		$dbw = wfGetDB( DB_MASTER, array(), $wiki );
		$row = $dbw->selectRow( 'user', User::selectFields(), array( 'user_id' => $userId ), __METHOD__ );

		$oldUser = User::newFromRow( $row );

		$reason = '[[m:Special:MyLanguage/Single User Login finalisation announcement|SUL finalization]] - [[phab:T5507]]';
		$caUser = new CentralAuthUser( $oldUser->getName(), CentralAuthUser::READ_LATEST );
		$maintScript = User::newFromName( 'Maintenance script' );
		$session = array(
			'userId' => $maintScript->getId(),
			'ip' => '127.0.0.1',
			'sessionId' => '0',
			'headers' => array(),
		);
		$data = array(
			'movepages' => true,
			'suppressredirects' => true,
			'reason' => $reason,
			'force' => true,
		);

		if ( $caUser->exists() && $caUser->isAttached() ) {
			$newUser = User::newFromName( 'Invalid username ' . (string)$caUser->getId(), 'usable' );
			$newCAUser = CentralAuthUser::getInstance( $newUser );
			if ( $newCAUser->exists() ) {
				$this->output( "ERROR: {$newCAUser->getName()} already exists!\n" );
				return;
			}
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
		} else { // Not attached, do a promote to global rename
			$newUser = User::newFromName( 'Invalid username ' . (string)$oldUser->getId(), 'usable' );
			$suffix = '~' . str_replace( '_', '-', $wiki );
			$newCAUser = new CentralAuthUser(
				$newUser->getName() . $suffix,
				CentralAuthUser::READ_LATEST
			);
			if ( $newCAUser->exists() ) {
				$this->output( "ERROR: {$newCAUser->getName()} already exists!\n" );
				return;
			}
			$statuses = new GlobalRenameUserStatus( $oldUser->getName() );
			$success = $statuses->setStatuses( array( array(
				'ru_wiki' => $wiki,
				'ru_oldname' => $oldUser->getName(),
				'ru_newname' => $newCAUser->getName(),
				'ru_status' => 'queued'
			) ) );

			if ( !$success ) {
				$this->output( "WARNING: Race condition, renameuser_status already set for {$newCAUser->getName()}. Skipping.\n" );
				return;
			}

			$this->output( "Set renameuser_status for {$newCAUser->getName()}.\n" );

			$job = new LocalRenameUserJob(
				Title::newFromText( 'Global rename job' ),
				array(
					'from' => $oldUser->getName(),
					'to' => $newCAUser->getName(),
					'renamer' => 'Maintenance script',
					'movepages' => true,
					'suppressredirects' => true,
					'promotetoglobal' => true,
					'reason' => $reason,
					'force' => true,
				)
			);

			JobQueueGroup::singleton( $wiki )->push( $job );
			// Log it
			$logger = new GlobalRenameUserLogger( $maintScript );
			$logger->logPromotion( $oldUser->getName(), $wiki, $newCAUser->getName(), $reason );
		}
	}

	protected function getCurrentRenameCount() {
		$row = CentralAuthUser::getCentralDB()->selectRow(
			array( 'renameuser_status' ),
			array( 'COUNT(*) as count' ),
			array(),
			__METHOD__
		);
		return (int)$row->count;
	}
}

$maintClass = 'RenameInvalidUsernames';
require_once RUN_MAINTENANCE_IF_MAIN;
