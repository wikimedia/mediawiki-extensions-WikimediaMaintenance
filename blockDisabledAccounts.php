<?php

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\UserBlockTarget;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserArray;
use Wikimedia\Rdbms\IExpression;

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

/**
 * Script to migrate disabled accounts to blocked accounts. This will also remove these users
 * from the 'inactive' group. Note that these users will still need sysadmin help to restore
 * their account as their email and password is set to null when the account is disabled.
 */
class BlockDisabledAccounts extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'reason', 'Block reason', false, true );
		$this->setBatchSize( 10 );
	}

	public function execute() {
		$dbr = $this->getReplicaDB();
		$inactive = $dbr->newSelectQueryBuilder()
			->select( 'ug_user' )
			->from( 'user_groups' )
			->where( [
				'ug_group' => 'inactive',
				$dbr->expr( 'ug_expiry', '=', null )->or( 'ug_expiry', '>=', $dbr->timestamp() ),
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$nulledDetailsQuery = $dbr->newSelectQueryBuilder()
			->select( 'user_id' )
			->from( 'user' )
			->where( [
				'user_password' => '',
				'user_email' => '',
			] )
			->caller( __METHOD__ );

		// Temporary users look like users with nulled details, so add an extra
		// condition so they aren't all blocked.
		$tempUserConfig = $this->getServiceContainer()->getTempUserConfig();
		if ( $tempUserConfig->isKnown() ) {
			$nulledDetailsQuery->andWhere(
				$tempUserConfig->getMatchCondition( $dbr, 'user_name', IExpression::NOT_LIKE )
			);
		}

		$nulledDetails = $nulledDetailsQuery->fetchFieldValues();

		$ids = array_unique( array_merge( $inactive, $nulledDetails ) );

		$disabledCount = count( $ids );
		if ( $disabledCount === 0 ) {
			$this->output( "No users in 'inactive' group, or with a blank password and email.\n" );
			return;
		}

		$counter = 0;
		$success = 0;

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$users = UserArray::newFromIDs( $ids );

		$this->output( "Starting migration...\n" );
		foreach ( $users as $user ) {
			if ( $counter >= $this->mBatchSize ) {
				$counter = 0;
				$lbFactory->waitForReplication();
			}

			$counter++;
			if ( $this->doBlockAndLog( $user ) ) {
				MediaWikiServices::getInstance()->getUserGroupManager()
					->removeUserFromGroup( $user, 'inactive' );
				$success++;
			}
		}

		$this->output( "Total $disabledCount users in 'inactive' group, or with " .
			"a blank password and email. Successfully migrated $success users\n" );
	}

	/**
	 * Attempt to block the given user. If user is already blocked, modify
	 * the existing block. If block was successful, insert log entry as well.
	 *
	 * @param User $user
	 *
	 * @return bool true on success, false on failure
	 */
	private function doBlockAndLog( User $user ) {
		$services = $this->getServiceContainer();
		$block = $user->getBlock();
		$alreadyBlocked = ( $block !== null );

		if ( $block === null ) {
			$block = new DatabaseBlock();
		}
		$reason = $this->getOption( 'reason', 'Convert disabled account to blocked account' );

		$scriptUser = $services->getUserFactory()->newFromName( 'Maintenance script' );

		$block->setTarget( new UserBlockTarget( $user->getUser() ) );
		$block->setBlocker( $scriptUser );
		$block->setReason( $reason );
		$block->setExpiry( 'infinity' );
		$block->isEmailBlocked( true );
		$block->isUsertalkEditAllowed( false );

		// Try to update block if user is already blocked. Otherwise, attempt to insert a new one.
		$store = $services->getDatabaseBlockStore();
		if ( $alreadyBlocked ) {
			$success = $store->updateBlock( $block );
		} else {
			$success = $store->insertBlock( $block );
		}

		if ( is_array( $success ) ) {
			$logAction = $alreadyBlocked ? 'reblock' : 'block';
			$logParams = [];
			$logParams['5::duration'] = 'infinity';
			$logParams['6::flags'] = 'noemail,nousertalk';

			$logEntry = new ManualLogEntry( 'block', $logAction );
			$logEntry->setTarget( Title::makeTitle( NS_USER, $user->getName() ) );
			$logEntry->setComment( $reason );
			$logEntry->setPerformer( $scriptUser );
			$logEntry->setParameters( $logParams );
			$logEntry->setRelations( [ 'ipb_id' => [ $success['id'] ] ] );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );
			return true;
		}

		return false;
	}
}

// @codeCoverageIgnoreStart
$maintClass = BlockDisabledAccounts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
