<?php
namespace MediaWiki\Extension\WikimediaMaintenance\Tests\Integration;

use CentralAuthTestUser;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\MainConfigNames;
use MediaWiki\Site\HashSiteStore;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use SendVerifyEmailReminderNotification;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers SendVerifyEmailReminderNotification
 * @group Database
 */
class SendVerifyEmailReminderNotificationTest extends MaintenanceBaseTestCase {
	private const OTHER_WIKI_ID = 'otherwiki';

	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );

		// Set up site configuration for the current wiki and a "foreign" wiki
		// which will be used to simulate unattached accounts.
		$currentSite = new MediaWikiSite();
		$currentSite->setGlobalId( WikiMap::getCurrentWikiId() );
		$currentSite->setPath( MediaWikiSite::PATH_PAGE, 'https://example.com/wiki/$1' );

		$otherSite = new MediaWikiSite();
		$otherSite->setGlobalId( self::OTHER_WIKI_ID );
		$otherSite->setPath( MediaWikiSite::PATH_PAGE, 'https://other.example.com/wiki/$1' );

		$this->setService( 'SiteLookup', new HashSiteStore( [ $currentSite, $otherSite ] ) );

		$this->overrideConfigValue(
			MainConfigNames::LocalDatabases, [ WikiMap::getCurrentWikiId(), self::OTHER_WIKI_ID ]
		);
	}

	protected function getMaintenanceClass() {
		return SendVerifyEmailReminderNotification::class;
	}

	/** @dataProvider provideShouldSendNotificationsToActiveUsers */
	public function testShouldSendNotificationsToActiveUsers( $batchSize, $expectedOutputStrings ) {
		ConvertibleTimestamp::setFakeTime( '20250501000000' );

		// Set up central users with various email confirmation states and home wikis.
		$this->createUsers( [
			self::OTHER_WIKI_ID => [
				[ 'name' => 'ActiveUser1', 'attrs' => [ 'gu_email_authenticated' => null, 'gu_id' => 10 ] ],
				[
					'name' => 'ActiveLockedUser',
					'attrs' => [ 'gu_email_authenticated' => null, 'gu_locked' => 1, 'gu_id' => 11 ],
				],
				[
					'name' => 'ActiveUserWithoutEmail',
					'attrs' => [ 'gu_email' => '', 'gu_email_authenticated' => null, 'gu_id' => 12 ],
				],
				[
					'name' => 'ActiveUserWithConfirmedEmail',
					'attrs' => [ 'gu_email_authenticated' => '20230102000000', 'gu_id' => 13 ],
				],
				[ 'name' => 'InactiveUser1', 'attrs' => [ 'gu_email_authenticated' => null, 'gu_id' => 14 ] ],
			],
			WikiMap::getCurrentWikiId() => [
				[ 'name' => 'ActiveUser2', 'attrs' => [ 'gu_email_authenticated' => null, 'gu_id' => 15 ] ],
				[
					'name' => 'UserWithNoActivityAtAll',
					'attrs' => [ 'gu_email_authenticated' => null, 'gu_id' => 16 ]
				],
			],
		] );

		// Insert last active timestamps for the users.
		$this->insertLastActiveTimestamps( [
			'ActiveUser1' => '20250401000000',
			'ActiveLockedUser' => '20250402000000',
			'ActiveUserWithoutEmail' => '20250403000000',
			'ActiveUserWithConfirmedEmail' => '20250404000000',
			'ActiveUser2' => '20250405000000',
			'InactiveUser1' => '20241101000000',
		] );

		$this->maintenance->loadWithArgv( [ '--batch-size', $batchSize ] );
		$this->maintenance->setArg( 'timestamp', '20250301000000' );
		$this->maintenance->execute();

		$actualOutput = $this->getActualOutputForAssertion();
		$this->assertStringContainsString(
			'Sending email verification reminder to users who have been active since 20250301000000:', $actualOutput
		);
		foreach ( $expectedOutputStrings as $expectedOutput ) {
			$this->assertStringContainsString( $expectedOutput, $actualOutput );
		}
		$this->assertStringContainsString(
			'Sent email to 5 users that have been active since 20250301000000', $actualOutput
		);
	}

	public static function provideShouldSendNotificationsToActiveUsers(): array {
		return [
			'--batch-size is 2' => [
				2,
				[
					'...would have sent verify-email-reminder for central IDs between 10 - 11',
					'...would have sent verify-email-reminder for central IDs between 12 - 13',
					'...would have sent verify-email-reminder for central IDs between 15 - 15',
				],
			],
			'--batch-size is 3' => [
				3,
				[
					'...would have sent verify-email-reminder for central IDs between 10 - 12',
					'...would have sent verify-email-reminder for central IDs between 13 - 15',
				],
			],
		];
	}

	/**
	 * Create test central users with specified attributes and home wikis.
	 * @param array $spec Map of home wiki ID to user data arrays.
	 * @return void
	 */
	private function createUsers( array $spec ): void {
		foreach ( $spec as $homeWiki => $users ) {
			foreach ( $users as $userSpec ) {
				$userSpec['attrs']['gu_home_db'] = $homeWiki;

				$centralUser = new CentralAuthTestUser(
					$userSpec['name'],
					'GUP@ssword',
					$userSpec['attrs'],
					[ [ $homeWiki, 'primary' ] ]
				);
				$centralUser->save( $this->getDb() );
			}
		}
	}

	/**
	 * Insert last active timestamps for users into cuci_user.
	 *
	 * @param array $timestampsByUserName Map of usernames to their last active timestamps.
	 * @return void
	 */
	private function insertLastActiveTimestamps( array $timestampsByUserName ): void {
		$centralUsers = CentralAuthServices::getGlobalUserSelectQueryBuilderFactory()
			->newGlobalUserSelectQueryBuilder()
			->whereUserNames( array_keys( $timestampsByUserName ) )
			->caller( __METHOD__ )
			->fetchCentralAuthUsers();

		$rows = [];

		foreach ( $centralUsers as $centralUser ) {
			$centralId = $centralUser->getId();
			$timestamp = $timestampsByUserName[$centralUser->getName()];
			$rows[] = [
				'ciu_central_id' => $centralId,
				// Not relevant
				'ciu_ciwm_id' => 1,
				'ciu_timestamp' => $this->getDb()->timestamp( $timestamp ),
			];
		}

		$this->getDb()
			->newInsertQueryBuilder()
			->insertInto( 'cuci_user' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}
}
