<?php

use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameFactory;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger;
use MediaWiki\Extension\CentralAuth\GlobalRename\LocalRenameJob\LocalRenameUserJob;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Rdbms\ILBFactory;

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * Rename users whose usernames are now invalid after
 * various MW changes, updates to interwiki map, etc.
 *
 * At the same time, convert them to a global account if necessary.
 *
 * The new name name may be specified as part of the list of names. Otherwise,
 * unattached accounts will be renamed to 'Invalid username $userId~$wiki' and
 * global accounts will be renamed to 'Invalid username $globalUserId'.
 *
 * @see bug T5507
 */
class RenameInvalidUsernames extends Maintenance {

	private ILBFactory $lbFactory;
	private CentralAuthDatabaseManager $caDatabaseManager;
	private GlobalRenameFactory $globalRenameFactory;

	/** @var string|null */
	protected $reason;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CentralAuth' );
		$this->addDescription( 'Rename invalid usernames to a generic one based on their user id' );
		$this->setBatchSize( 30 );
		$this->addOption( 'list',
			'List of users to fix, as a TSV file. '
			. 'Column 1 is the wiki, 2 the user ID, and (optional) 3 is the new name.',
			true, true );
		$this->addOption( 'reason', 'Rename reason to use for the renames', true, true );
	}

	private function initServices() {
		$services = MediaWikiServices::getInstance();
		$this->lbFactory = $services->getDBLoadBalancerFactory();
		$this->caDatabaseManager = $services->get( 'CentralAuth.CentralAuthDatabaseManager' );
		$this->globalRenameFactory = $services->get( 'CentralAuth.GlobalRenameFactory' );
	}

	public function execute() {
		$this->reason = $this->getOption( 'reason' );
		$list = $this->getOption( 'list' );
		$file = fopen( $list, 'r' );
		if ( $file === false ) {
			$this->output( "ERROR - Could not open file: $list\n" );
			exit( 1 );
		} else {
			$this->output( "Reading from $list\n" );
		}
		$count = 0;
		$batchSize = $this->getBatchSize();
		while ( $line = trim( fgets( $file ) ) ) {
			$this->output( "$line\n" );
			// xxwiki	#####
			$exp = explode( "\t", $line );
			$this->rename( $exp[1], $exp[0], $exp[2] ?? '' );
			$count++;
			if ( $count > $batchSize ) {
				$count = 0;
				$this->output( "Sleep for 5 and waiting for replicas...\n" );
				$this->caDatabaseManager->waitForReplication();
				$this->lbFactory->waitForReplication();
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

	protected function rename( $userId, $wiki, $newName = '' ) {
		if ( $newName === '' ) {
			$newName = null;
		}

		$dbw = wfGetDB( DB_PRIMARY, [], $wiki );
		$userQuery = User::getQueryInfo();
		$row = $dbw->selectRow(
			$userQuery['tables'], $userQuery['fields'], [ 'user_id' => $userId ],
			__METHOD__, [], $userQuery['joins']
		);

		$oldUser = User::newFromRow( $row );

		$caUser = new CentralAuthUser( $oldUser->getName(), IDBAccessObject::READ_LATEST );
		$maintScript = User::newFromName( User::MAINTENANCE_SCRIPT_USER );
		$session = [
			'userId' => $maintScript->getId(),
			'ip' => '127.0.0.1',
			'sessionId' => '0',
			'headers' => [],
		];
		$data = [
			'movepages' => true,
			'suppressredirects' => true,
			'reason' => $this->reason,
			'force' => true,
		];

		$jobQueueGroupFactory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();
		if ( $caUser->exists() && $caUser->isAttached() ) {
			$newName ??= 'Invalid username ' . (string)$caUser->getId();

			$newCAUser = CentralAuthUser::getInstanceByName( $newName );
			if ( $newCAUser->exists() ) {
				$this->output( "ERROR: {$newCAUser->getName()} already exists!\n" );
				return;
			}

			$this->globalRenameFactory
				->newGlobalRenameUser(
					$maintScript,
					$caUser,
					$newName
				)
				->withSession( $session )
				->rename( $data );
		} else { // Not attached, do a promote to global rename
			$suffix = '~' . str_replace( '_', '-', $wiki );
			$newUser = User::newFromName(
				$newName ?? 'Invalid username ' . (string)$oldUser->getId() . $suffix, 'usable'
			);
			$newCAUser = new CentralAuthUser( $newUser->getName(), IDBAccessObject::READ_LATEST );
			if ( $newCAUser->exists() ) {
				$this->output( "ERROR: {$newCAUser->getName()} already exists!\n" );
				return;
			}
			$success = $this->globalRenameFactory
				->newGlobalRenameUserStatus( $oldUser->getName() )
				->setStatuses( [ [
					'ru_wiki' => $wiki,
					'ru_oldname' => $oldUser->getName(),
					'ru_newname' => $newCAUser->getName(),
					'ru_status' => 'queued'
				] ] );

			if ( !$success ) {
				$this->output(
					"WARNING: Race condition, renameuser_status already set for " .
						"{$newCAUser->getName()}. Skipping.\n"
				);
				return;
			}

			$this->output( "Set renameuser_status for {$newCAUser->getName()}.\n" );

			$job = new LocalRenameUserJob(
				Title::newFromText( 'Global rename job' ),
				[
					'from' => $oldUser->getName(),
					'to' => $newCAUser->getName(),
					'renamer' => User::MAINTENANCE_SCRIPT_USER,
					'movepages' => true,
					'suppressredirects' => true,
					'promotetoglobal' => true,
					'reason' => $this->reason,
					'force' => true,
				]
			);

			$jobQueueGroupFactory->makeJobQueueGroup( $wiki )->push( $job );
			// Log it
			$logger = new GlobalRenameUserLogger( $maintScript );
			$logger->logPromotion( $oldUser->getName(), $wiki, $newCAUser->getName(), $this->reason );
		}
	}

	protected function getCurrentRenameCount() {
		// TODO: why does this need a primary connection?
		$row = CentralAuthServices::getDatabaseManager()->getCentralPrimaryDB()->selectRow(
			[ 'renameuser_status' ],
			[ 'COUNT(*) as count' ],
			[],
			__METHOD__
		);
		return (int)$row->count;
	}
}

$maintClass = RenameInvalidUsernames::class;
require_once RUN_MAINTENANCE_IF_MAIN;
