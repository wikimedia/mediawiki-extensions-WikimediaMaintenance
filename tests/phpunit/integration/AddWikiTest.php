<?php

namespace MediaWiki\Extension\WikimediaMaintenance\Tests\Integration;

use AddWiki;
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

	private function addMockArguments( $language = null, $site = null, $dbname = null, $domain = null ) {
		$this->maintenance->setArg( 'language', $language ?? 'en' );
		$this->maintenance->setArg( 'site', $site ?? 'wikipedia' );
		$this->maintenance->setArg( 'dbname', $dbname ?? 'enwiki' );
		$this->maintenance->setArg( 'domain', $domain ?? 'en.wikipedia.org' );
	}

	public function testExecuteWhenWmgVersionNumberNotSet() {
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/\$wmgVersionNumber is not set/' );
		$this->addMockArguments();
		$this->maintenance->execute();
	}

	public function testExecuteForUndefinedLanguageName() {
		global $wmgVersionNumber;
		$wmgVersionNumber = 'master';
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/Language fakelanguage not found/' );
		$this->addMockArguments( 'fakelanguage' );
		$this->maintenance->execute();
	}
}
