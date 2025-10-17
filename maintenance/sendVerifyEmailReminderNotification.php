<?php

use MediaWiki\CheckUser\Services\CheckUserCentralIndexLookup;
use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\User\GlobalUserSelectQueryBuilderFactory;
use MediaWiki\Extension\Notifications\Controller\NotificationController;
use MediaWiki\Extension\Notifications\Jobs\NotificationJob;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\User\ActorStoreFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

class SendVerifyEmailReminderNotification extends Maintenance {

	private GlobalUserSelectQueryBuilderFactory $globalUserSelectQueryBuilderFactory;
	private CentralAuthDatabaseManager $centralAuthDatabaseManager;
	private JobQueueGroupFactory $jobQueueGroupFactory;
	private ActorStoreFactory $actorStoreFactory;
	private LoggerInterface $logger;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CheckUser' );
		$this->requireExtension( 'CentralAuth' );
		$this->requireExtension( 'Echo' );
		$this->addDescription(
			'Send the verify-email-reminder notification to users on SUL wikis who were active within ' .
			'the specified time period'
		);
		$this->addArg(
			'cutoff',
			'The maximum number of seconds ago the users last action must have been for ' .
			'them to receive a verification reminder email'
		);
		$this->setBatchSize( 1000 );
	}

	public function execute() {
		$this->globalUserSelectQueryBuilderFactory = CentralAuthServices::getGlobalUserSelectQueryBuilderFactory(
			$this->getServiceContainer()
		);
		$this->centralAuthDatabaseManager = CentralAuthServices::getDatabaseManager( $this->getServiceContainer() );
		$this->jobQueueGroupFactory = $this->getServiceContainer()->getJobQueueGroupFactory();
		$this->actorStoreFactory = $this->getServiceContainer()->getActorStoreFactory();
		$this->logger = LoggerFactory::getInstance( 'WikimediaMaintenance' );

		/** @var CheckUserCentralIndexLookup $checkUserCentralIndexLookup */
		$checkUserCentralIndexLookup = $this->getServiceContainer()->get( 'CheckUserCentralIndexLookup' );

		// Subtract the cutoff seconds from the current time to get the TS_MW absolute cutoff that can be passed
		// to CheckUserCentralIndexLookup::getUsersActiveSinceTimestamp
		$cutoffSeconds = (int)$this->getArg( 'cutoff' );
		$timestamp = ConvertibleTimestamp::convert( TS_MW, ConvertibleTimestamp::time() - $cutoffSeconds );

		$emailedCount = 0;
		$usersWithConfirmedEmail = 0;
		$usersCheckedCount = 0;
		$centralIdBatch = [];
		$batchSize = $this->getBatchSize();

		$this->output( "Sending email verification reminder to users who have been active since $timestamp\n" );

		$activeUsers = $checkUserCentralIndexLookup->getUsersActiveSinceTimestamp( $timestamp, $batchSize );

		$this->output( "Sending email verification reminder to users who have been active since $timestamp:\n" );

		foreach ( $activeUsers as $centralId ) {
			$centralIdBatch[] = $centralId;

			if ( count( $centralIdBatch ) >= $batchSize ) {
				[ $emailedCountInBatch, $usersWithConfirmedEmailInBatch ] = $this->sendNotifications( $centralIdBatch );

				$usersCheckedCount += count( $centralIdBatch );
				$emailedCount += $emailedCountInBatch;
				$usersWithConfirmedEmail += $usersWithConfirmedEmailInBatch;

				$centralIdBatch = [];
			}
		}

		if ( count( $centralIdBatch ) > 0 ) {
			[ $emailedCountInBatch, $usersWithConfirmedEmailInBatch ] = $this->sendNotifications( $centralIdBatch );

			$usersCheckedCount += count( $centralIdBatch );
			$emailedCount += $emailedCountInBatch;
			$usersWithConfirmedEmail += $usersWithConfirmedEmailInBatch;
		}

		$this->output(
			"Sent verification reminder to $emailedCount users active since $timestamp. " .
			"Checked a total of $usersCheckedCount users, where $usersWithConfirmedEmail " .
			"had already confirmed their email.\n"
		);
	}

	/**
	 * Checks if the given batch of central IDs needs a email verification reminder notification
	 * and creates one for each user that needs this on their home wiki
	 *
	 * @param int[] $centralIdBatch The list of central user IDs to check
	 * @return int[] The number of notifications sent as the first value, and the
	 *   number of users who have already confirmed their email as the second value
	 */
	private function sendNotifications( array $centralIdBatch ): array {
		$minId = min( $centralIdBatch );
		$maxId = max( $centralIdBatch );
		$this->output( "...checking if verification reminder is needed for central IDs between $minId - $maxId\n" );

		// Fetch the central users in this batch of central IDs that have an email and are not locked
		$centralAuthDbr = $this->centralAuthDatabaseManager->getCentralReplicaDB();
		$centralUsers = $this->globalUserSelectQueryBuilderFactory
			->newGlobalUserSelectQueryBuilder()
			->whereGlobalUserIds( $centralIdBatch )
			->whereLocked( false )
			->where( $centralAuthDbr->expr( 'gu_email', '!=', '' ) )
			->caller( __METHOD__ )
			->fetchCentralAuthUsers();

		$usersWithConfirmedEmailCount = 0;
		$centralUsersByHomeWiki = [];

		foreach ( $centralUsers as $centralUser ) {
			// Don't send a confirmation reminder if there is no email to confirm.
			if ( !Sanitizer::validateEmail( $centralUser->getEmail() ) ) {
				continue;
			}

			// Don't send a reminder if the user has already confirmed their email.
			if ( $centralUser->getEmailAuthenticationTimestamp() ) {
				$usersWithConfirmedEmailCount += 1;
				continue;
			}

			$homeWiki = $centralUser->getHomeWiki();
			// The user must be attached on at least one wiki to be able to send a notification.
			if ( $homeWiki === null ) {
				$this->logger->info(
					'User with central ID {id} does not have home wiki, so cannot send email verification reminder',
					[ 'id' => $centralUser->getId() ]
				);
				continue;
			}

			$centralUsersByHomeWiki[$homeWiki][] = $centralUser;
		}

		$notificationCount = 0;

		// Send an Echo confirmation reminder notification to each user on their home wiki.
		// Defer notification creation via a job to ensure it is executed on the correct wiki,
		// since the maintenance script will be running on a different wiki.
		foreach ( $centralUsersByHomeWiki as $wikiId => $batch ) {
			$localUserIds = $this->actorStoreFactory->getActorStore( $wikiId )
				->newSelectQueryBuilder()
				->select( 'actor_user' )
				->whereUserNames( array_map( static fn ( $centralUser ) => $centralUser->getName(), $batch ) )
				->caller( __METHOD__ )
				->fetchFieldValues();

			$event = Event::newFromArray( [
				'event_type' => 'verify-email-reminder',
				'event_deleted' => 0,
				'event_extra' => json_encode( [
					'recipients' => $localUserIds,
				] ),
			] );

			$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup( $wikiId );
			$jobQueueGroup->push( new NotificationJob(
				Title::makeTitle( NS_SPECIAL, 'Blankpage' ),
				NotificationController::getEventParams( $event )
			) );

			$notificationCount += count( $localUserIds );
		}

		return [ $notificationCount, $usersWithConfirmedEmailCount ];
	}
}

// @codeCoverageIgnoreStart
$maintClass = SendVerifyEmailReminderNotification::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
