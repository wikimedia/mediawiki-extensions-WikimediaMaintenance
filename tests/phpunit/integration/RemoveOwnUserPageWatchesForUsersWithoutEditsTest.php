<?php

namespace MediaWiki\Extension\WikimediaMaintenance\Tests\Integration;

use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\User\UserIdentity;
use RemoveOwnUserPageWatchesForUsersWithoutEdits;

/**
 * @covers \RemoveOwnUserPageWatchesForUsersWithoutEdits
 * @group Database
 */
class RemoveOwnUserPageWatchesForUsersWithoutEditsTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return RemoveOwnUserPageWatchesForUsersWithoutEdits::class;
	}

	private function setEditCount( UserIdentity $user, int $count ): void {
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_editcount' => $count ] )
			->where( [ 'user_id' => $user->getId() ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function watchOwnPagesAndOther( UserIdentity $user ): PageReferenceValue {
		$store = $this->getServiceContainer()->getWatchedItemStore();
		$dbKey = strtr( $user->getName(), ' ', '_' );
		$other = PageReferenceValue::localReference( NS_MAIN, 'Some_other_page' );
		$store->addWatchBatchForUser( $user, [
			PageReferenceValue::localReference( NS_USER, $dbKey ),
			PageReferenceValue::localReference( NS_USER_TALK, $dbKey ),
			$other,
		] );
		return $other;
	}

	private function isWatchingOwnPages( UserIdentity $user ): array {
		$store = $this->getServiceContainer()->getWatchedItemStore();
		$dbKey = strtr( $user->getName(), ' ', '_' );
		return [
			$store->loadWatchedItem( $user, PageReferenceValue::localReference( NS_USER, $dbKey ) ) !== false,
			$store->loadWatchedItem( $user, PageReferenceValue::localReference( NS_USER_TALK, $dbKey ) ) !== false,
		];
	}

	public function testExecute() {
		$store = $this->getServiceContainer()->getWatchedItemStore();

		$noEdits = $this->getMutableTestUser()->getUserIdentity();
		$withEdits = $this->getMutableTestUser()->getUserIdentity();
		$noEditsOther = $this->watchOwnPagesAndOther( $noEdits );
		$this->watchOwnPagesAndOther( $withEdits );
		$this->setEditCount( $noEdits, 0 );
		$this->setEditCount( $withEdits, 3 );

		$this->maintenance->execute();

		$this->assertSame( [ false, false ], $this->isWatchingOwnPages( $noEdits ) );
		$this->assertNotFalse(
			$store->loadWatchedItem( $noEdits, $noEditsOther ),
			'Other watches are kept'
		);
		$this->assertSame( [ true, true ], $this->isWatchingOwnPages( $withEdits ) );
		$this->assertStringContainsString(
			'Removed 2 watchlist entries from 1 users',
			$this->getActualOutputForAssertion()
		);
	}

	public function testExecuteDryRun() {
		$noEdits = $this->getMutableTestUser()->getUserIdentity();
		$this->watchOwnPagesAndOther( $noEdits );
		$this->setEditCount( $noEdits, 0 );

		$this->maintenance->setOption( 'dry-run', true );
		$this->maintenance->execute();

		$this->assertSame( [ true, true ], $this->isWatchingOwnPages( $noEdits ) );
		$this->assertStringContainsString(
			'Would have removed 2 watchlist entries from 1 users',
			$this->getActualOutputForAssertion()
		);
	}
}
