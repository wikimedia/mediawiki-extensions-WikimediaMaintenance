<?php

namespace MediaWiki\Extension\WikimediaMaintenance\Tests\Integration;

use AddWiki;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

/**
 * @covers \AddWiki
 * @group Database
 * @author Dreamy Jazz
 * @group WikimediaMaintenance
 */
class AddWikiTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return AddWiki::class;
	}

	public function testExecuteWhenArgProvided() {
		$this->maintenance->setArg( 0, 'test' );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/This script no longer takes arguments/' );
		$this->maintenance->execute();
	}

	public function testExecuteWhenSiteAlreadyExists() {
		$dbName = $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::DBname );

		$this->expectCallToFatalError();
		$this->expectOutputRegex(
			"/The wiki \"$dbName\" already exists. " .
			'Use --allow-existing to run installer tasks on an existing wiki./'
		);
		$this->maintenance->execute();
	}

	public function testExecuteForUndefinedLanguageName() {
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'fakelanguage' );
		$this->maintenance->setOption( 'allow-existing', true );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/Language fakelanguage not found/' );
		$this->maintenance->execute();
	}
}
