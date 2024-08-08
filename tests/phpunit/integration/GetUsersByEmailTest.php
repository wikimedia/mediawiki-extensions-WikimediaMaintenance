<?php

namespace MediaWiki\Extension\WikimediaMaintenance\Tests\Integration;

use GetUsersByEmail;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

/**
 * @covers \GetUsersByEmail
 * @group Database
 * @author Dreamy Jazz
 */
class GetUsersByEmailTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return GetUsersByEmail::class;
	}

	public function testExecuteWhenNoMatchingEmail() {
		// Create a testing user which has an email other than we are looking for.
		$testUser1 = $this->getMutableTestUser()->getUser();
		$testUser1->setEmail( 'testing@test.com' );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/test@test\.com was not found on the wiki/' );
		$this->maintenance->setOption( 'email', ' test@test.com ' );
		$this->maintenance->execute();
	}

	public function testExecuteWhenMatchingEmail() {
		// Create three testing users, with two having the matching email
		$testUser1 = $this->getMutableTestUser()->getUser();
		$testUser1->setEmail( 'test@test.com' );
		$testUser1->saveSettings();
		$testUser2 = $this->getMutableTestUser()->getUser();
		$testUser2->setEmail( 'test@test.com' );
		$testUser2->saveSettings();
		$this->getMutableTestUser()->getUser();
		$this->maintenance->setOption( 'email', 'test@test.com' );
		$this->maintenance->execute();
		$this->assertArrayEquals(
			[
				[ 'username' => $testUser1->getName(), 'email' => 'test@test.com', 'email_authenticated_date' => null ],
				[ 'username' => $testUser2->getName(), 'email' => 'test@test.com', 'email_authenticated_date' => null ],
			],
			json_decode( $this->getActualOutputForAssertion(), true ),
			false,
			true,
			'Output JSON was not as expected'
		);
	}
}
