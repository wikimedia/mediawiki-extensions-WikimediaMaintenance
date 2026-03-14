<?php

namespace MediaWiki\Extension\WikimediaMaintenance\Tests\Integration;

use MediaWiki\Mail\MailAddress;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use SendBulkEmails;

/**
 * @covers SendBulkEmails
 * @group Database
 * @group WikimediaMaintenance
 */
class SendBulkEmailsTest extends MaintenanceBaseTestCase {
	use MockAuthorityTrait;

	protected function getMaintenanceClass(): string {
		return SendBulkEmails::class;
	}

	public function testExecuteWhenBodyIsNotAnExistingFilename() {
		$this->maintenance->setOption( 'body', 'NonExistingTestFileAbc' );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/ERROR - File not found: NonExistingTestFileAbc/' );
		$this->maintenance->execute();
	}

	public function testExecuteWhenFromIsANonExistingUser() {
		$this->maintenance->setOption( 'body', $this->getNewTempFile() );
		$this->maintenance->setOption( 'from', 'NonExistingTestUser1234' );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/ERROR - Unknown user NonExistingTestUser1234/' );
		$this->maintenance->execute();
	}

	public function testExecuteWhenReplyToIsANonExistingUser() {
		$this->maintenance->setOption( 'body', $this->getNewTempFile() );
		$this->maintenance->setOption( 'reply-to', 'NonExistingTestUser1234' );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/ERROR - Unknown user NonExistingTestUser1234/' );
		$this->maintenance->execute();
	}

	public function testExecuteWhenOptOutIsANonExistingPage() {
		$nonExistingTestPage = $this->getNonexistingTestPage()->getTitle()->getPrefixedText();

		$this->maintenance->setOption( 'body', $this->getNewTempFile() );
		$this->maintenance->setOption( 'optout', $nonExistingTestPage );
		$this->expectCallToFatalError();
		$this->expectOutputRegex(
			'/ERROR - Opt-out page \'' . preg_quote( $nonExistingTestPage ) . '\' not found/'
		);
		$this->maintenance->execute();
	}

	public function testExecuteWhenOptOutMissingStartMarker() {
		$nonExistingTestPage = $this->getExistingTestPage()->getTitle()->getPrefixedText();

		$this->maintenance->setOption( 'body', $this->getNewTempFile() );
		$this->maintenance->setOption( 'optout', $nonExistingTestPage );
		$this->maintenance->setOption( 'optout-start', 'opt-out-start' );
		$this->expectCallToFatalError();
		$this->expectOutputRegex(
			'/ERROR - List marker \'opt-out-start\' not found/'
		);
		$this->maintenance->execute();
	}

	public function testExecuteWhenToIsNotAFile() {
		$this->maintenance->setOption( 'body', $this->getNewTempFile() );
		$this->maintenance->setOption( 'to', 'NonExistingTestFileAbc' );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/ERROR - File not found: NonExistingTestFileAbc/' );
		$this->maintenance->execute();
	}

	public function testExecuteWhenUsersExcluded() {
		$hookCalled = false;
		$this->setTemporaryHook(
			'UserMailerTransformContent',
			static function () use ( &$hookCalled ) {
				$hookCalled = true;
			}
		);

		$blockedUser = $this->getMutableTestUser()->getUserIdentity();
		$this->getServiceContainer()->getBlockUserFactory()
			->newBlockUser(
				$blockedUser,
				$this->mockRegisteredUltimateAuthority(),
				'infinity'
			)
			->placeBlock();

		$userWithoutEmail = $this->getMutableTestUser()->getUser();
		$userWithoutEmail->setEmail( '' );
		$userWithoutEmail->saveSettings();

		$userInOptOut = $this->getMutableTestUser()->getUser();
		$userInOptOut->setEmail( 'test@example.com' );
		$userInOptOut->setEmailAuthenticationTimestamp( '20250403020100' );
		$userInOptOut->saveSettings();
		$this->editPage(
			Title::newFromText( 'Opt-out-list' ),
			"<!-- BEGIN OPT-OUT LIST -->\n$userInOptOut"
		);

		$toFile = $this->getNewTempFile();
		file_put_contents( $toFile, "NonExistingUser\n$blockedUser\n$userWithoutEmail\n$userInOptOut" );

		$this->maintenance->setOption( 'body', $this->getNewTempFile() );
		$this->maintenance->setOption( 'to', $toFile );
		$this->maintenance->setOption( 'from', $this->getTestSysop()->getUserIdentity()->getName() );
		$this->maintenance->setOption( 'subject', 'Test' );
		$this->maintenance->setOption( 'exclude-blocked', 1 );
		$this->maintenance->setOption( 'optout', 'Opt-out-list' );

		$this->maintenance->execute();

		$this->assertFalse( $hookCalled, 'Hook should not be called if no users should have been emailed' );

		$actualOutput = $this->getActualOutputForAssertion();
		$this->assertStringContainsString( 'ERROR - Unknown user NonExistingUser', $actualOutput );
		$this->assertStringContainsString( "WARNING - User $blockedUser is blocked", $actualOutput );
		$this->assertStringContainsString( "WARNING - User $userWithoutEmail can't receive mail", $actualOutput );
		$this->assertStringContainsString( "WARNING - User $userInOptOut on opt-out list", $actualOutput );
	}

	public function testExecuteWhenEmailsSent() {
		$fromUser = $this->getMutableTestUser()->getUser();
		$fromUser->setEmail( 'example@test.com' );
		$fromUser->saveSettings();

		$usersEmailed = [];
		$this->setTemporaryHook(
			'AlternateUserMailer',
			function ( array $headers, $to, MailAddress $from, string $subject, string $body ) use (
				&$usersEmailed
			) {
				$to = (array)$to;
				$usersEmailed = array_merge(
					$usersEmailed,
					array_map( static fn ( MailAddress $toItem ) => $toItem->address, $to )
				);

				$this->assertSame( 'example@test.com', $from->address );
				$this->assertSame( 'Test', $subject );
				$this->assertSame( str_repeat( 'a', 255 ), $body );
				$this->assertArrayContains(
					[ 'Precedence' => 'bulk' ],
					$headers
				);

				return false;
			}
		);

		$firstUser = $this->getMutableTestUser()->getUser();
		$firstUser->setEmail( 'test@example.com' );
		$firstUser->setEmailAuthenticationTimestamp( '20250403020100' );
		$firstUser->saveSettings();

		$secondUser = $this->getMutableTestUser()->getUser();
		$secondUser->setEmail( 'test2@example.com' );
		$secondUser->setEmailAuthenticationTimestamp( '20250403020101' );
		$secondUser->saveSettings();

		$toFile = $this->getNewTempFile();
		file_put_contents( $toFile, $firstUser->getName() . "\n" . $secondUser->getName() );

		$bodyFile = $this->getNewTempFile();
		file_put_contents( $bodyFile, str_repeat( 'a', 255 ) );

		$this->maintenance->setOption( 'body', $bodyFile );
		$this->maintenance->setOption( 'to', $toFile );
		$this->maintenance->setOption( 'from', $fromUser->getName() );
		$this->maintenance->setOption( 'subject', 'Test' );

		$this->maintenance->execute();

		$actualOutput = $this->getActualOutputForAssertion();
		$this->assertStringContainsString(
			"INFO - Emailing $firstUser <{$firstUser->getEmail()}>",
			$actualOutput
		);
		$this->assertStringContainsString(
			"INFO - Emailing $secondUser <{$secondUser->getEmail()}>",
			$actualOutput
		);

		$this->assertArrayEquals(
			[ $firstUser->getEmail(), $secondUser->getEmail() ],
			$usersEmailed,
			false, false,
			'Users emailed was not as expected'
		);
	}
}
