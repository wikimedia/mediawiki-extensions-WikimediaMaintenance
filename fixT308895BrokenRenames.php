<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameRequest;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUser;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserDatabaseUpdates;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserStatus;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserRigorOptions;

class FixT308895BrokenRenames extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Fixes renames that were approved in the queue but never executed due to T308895' );
		$this->requireExtension( 'CentralAuth' );

		$this->addOption( 'user', 'User to execute the renames as', true, true );
		$this->addOption( 'reason', 'Reason to specify in rename logs', true, true );
		$this->addOption( 'min-id', 'Minimum request ID to use to filter for new requests only', true, true );
		$this->addOption( 'execute', 'Will perform a dry run if this is not set' );
	}

	public function execute() {
		$mwServices = MediaWikiServices::getInstance();

		$caDbManager = CentralAuthServices::getDatabaseManager( $mwServices );
		$caDbr = $caDbManager->getCentralDB( DB_REPLICA );
		$requestStore = CentralAuthServices::getGlobalRenameRequestStore( $mwServices );

		$username = $this->getOption( 'user' );
		$userFactory = $mwServices->getUserFactory();
		$performer = $userFactory->newFromName( $username );

		if ( $performer === null || !$performer->isRegistered() ) {
			$this->fatalError( "User $username does not exist." );
		}

		$execute = $this->hasOption( 'execute' );
		$session = [
			'userId' => $performer->getId(),
			'ip' => '127.0.0.1',
			'sessionId' => '0',
			'headers' => [],
		];
		$data = [
			'movepages' => true,
			'suppressredirects' => false,
			'reason' => $this->getOption( 'reason' ),
		];

		$renames = $caDbr->selectFieldValues(
			'renameuser_queue',
			'rq_id',
			[
				'rq_id > ' . (int)$this->getOption( 'min-id' ),
				'rq_status' => GlobalRenameRequest::APPROVED,
				'rq_wiki IS NOT NULL',
			],
			__METHOD__
		);

		foreach ( $renames as $id ) {
			$request = $requestStore->newFromId( (int)$id );

			$caUser = CentralAuthUser::getPrimaryInstanceByName( $request->getName() );
			if ( !$caUser->exists() ) {
				$this->output(
					"SKIP: {$caUser->getName()} (#{$request->getId()}), does not exist (already renamed?)\n"
				);
				continue;
			}

			$oldUser = $userFactory->newFromName( $request->getName() );
			$newUser = $userFactory->newFromName( $request->getNewName(), UserRigorOptions::RIGOR_CREATABLE );

			if ( !$oldUser || !$newUser ) {
				$this->output(
					"SKIP: {$caUser->getName()} (#{$request->getId()}), could not create user objects\n"
				);
				continue;
			}

			if ( $execute ) {
				$this->output( "Renaming {$oldUser->getName()} to {$newUser->getName()}\n" );

				$globalRenameUser = new GlobalRenameUser(
					$performer,
					$oldUser,
					CentralAuthUser::getInstance( $oldUser ),
					$newUser,
					CentralAuthUser::getInstance( $newUser ),
					new GlobalRenameUserStatus( $newUser->getName() ),
					$mwServices->getJobQueueGroupFactory(),
					new GlobalRenameUserDatabaseUpdates( $caDbManager ),
					new GlobalRenameUserLogger( $performer ),
					$session
				);

				$status = $globalRenameUser->rename( $data );

				if ( !$status->isGood() ) {
					$this->output(
						"FAIL: {$caUser->getName()} (#{$request->getId()}): {$status->getMessage()->text()}\n"
					);
				}
			} else {
				$this->output( "DRY-RUN: Would have renamed {$request->getName()} to {$request->getNewName()}\n" );
			}
		}
	}
}

$maintClass = FixT308895BrokenRenames::class;
require_once RUN_MAINTENANCE_IF_MAIN;
