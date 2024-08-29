<?php

namespace MediaWiki\Extension\WikimediaMaintenance\Tests\Integration;

use BlockDisabledAccounts;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;

/**
 * @covers \BlockDisabledAccounts
 * @group Database
 * @author Dreamy Jazz
 */
class BlockDisabledAccountsTest extends MaintenanceBaseTestCase {

	use TempUserTestTrait;

	protected function getMaintenanceClass() {
		return BlockDisabledAccounts::class;
	}

	public function testExecuteWhenNoMatchingAccounts() {
		// Get a test user and temporary account
		$this->enableAutoCreateTempUser();
		$this->getTestUser()->getUserIdentity();
		$this->getServiceContainer()->getTempUserCreator()->create( null, new FauxRequest() );
		// Run the maintenance script and expect that no accounts are found
		$this->expectOutputString( "No users in 'inactive' group, or with a blank password and email.\n" );
		$this->maintenance->execute();
	}
}
