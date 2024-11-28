<?php

namespace MediaWiki\Extension\WikimediaMaintenance\Tests\Integration;

use AddWiki;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

/**
 * @covers \AddWiki
 * @group Database
 * @author Dreamy Jazz
 */
class AddWikiTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return AddWiki::class;
	}

	public function testExecuteForUndefinedLanguageName() {
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'fakelanguage' );
		$this->maintenance->setOption( 'allow-existing', true );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/Language fakelanguage not found/' );
		$this->maintenance->execute();
	}
}
