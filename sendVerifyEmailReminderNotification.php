<?php

use MediaWiki\CheckUser\Services\CheckUserCentralIndexLookup;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

class SendVerifyEmailReminderNotification extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CheckUser' );
		$this->addDescription(
			'Send the verify-email-reminder notification to users on SUL wikis who were active within ' .
			'the specified time period'
		);
		$this->addArg(
			'timestamp',
			'The TS_MW formatted timestamp to use for selecting users to notify. Any users with activity ' .
			'recorded after this timestamp will be notified.'
		);
		$this->setBatchSize( 1000 );
	}

	public function execute() {
		/** @var CheckUserCentralIndexLookup $checkUserCentralIndexLookup */
		$checkUserCentralIndexLookup = $this->getServiceContainer()->get( 'CheckUserCentralIndexLookup' );
		$timestamp = $this->getArg( 'timestamp' );

		$count = 0;
		$centralIdBatch = [];
		$batchSize = $this->getBatchSize();

		$activeUsers = $checkUserCentralIndexLookup->getUsersActiveSinceTimestamp( $timestamp, $batchSize );

		$this->output( "Sending email verification reminder to users who have been active since $timestamp:\n" );

		foreach ( $activeUsers as $centralId ) {
			$centralIdBatch[] = $centralId;

			if ( count( $centralIdBatch ) >= $batchSize ) {
				$count += $this->sendNotifications( $centralIdBatch );
				$centralIdBatch = [];
			}
		}

		if ( count( $centralIdBatch ) > 0 ) {
			$count += $this->sendNotifications( $centralIdBatch );
		}

		$this->output( "Sent email to $count users that have been active since $timestamp\n" );
	}

	/**
	 * Send an Echo email confirmation reminder notification to a list of users.
	 *
	 * @param int[] $centralIdBatch The list of central user IDs to notify.
	 * @return int The number of notifications sent.
	 */
	private function sendNotifications( array $centralIdBatch ): int {
		$minId = min( $centralIdBatch );
		$maxId = max( $centralIdBatch );
		$this->output( "...would have sent verify-email-reminder for central IDs between $minId - $maxId\n" );

		return count( $centralIdBatch );
	}
}

// @codeCoverageIgnoreStart
$maintClass = SendVerifyEmailReminderNotification::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
