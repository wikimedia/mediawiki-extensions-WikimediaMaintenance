<?php
/**
 * @license GPL-2.0-or-later
 * @file
 */

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Remove the watchlist entries for a user's own user page and user talk page
 * if that user has never edited.
 *
 * Until $wgWatchUserPageOnAccountCreation was introduced, every new account
 * automatically watched its own user page and user talk page. For users that
 * never edited, those entries are almost never wanted and only bloat the
 * watchlist table.
 *
 * @see T442779
 */
class RemoveOwnUserPageWatchesForUsersWithoutEdits extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Remove watchlist entries for their own user page and user talk page ' .
			'from users with an edit count of 0'
		);
		$this->addOption( 'dry-run', 'Only report what would be removed' );
		$this->addOption( 'start', 'User ID to start from (inclusive)', false, true );
		$this->addOption( 'end', 'User ID to stop at (inclusive)', false, true );
		$this->setBatchSize( 500 );
	}

	public function execute() {
		$dryRun = $this->hasOption( 'dry-run' );
		$batchSize = $this->getBatchSize();
		$lastId = (int)$this->getOption( 'start', 1 ) - 1;
		$end = $this->hasOption( 'end' ) ? (int)$this->getOption( 'end' ) : null;

		$dbr = $this->getReplicaDB();
		$watchedItemStore = $this->getServiceContainer()->getWatchedItemStore();

		$usersChecked = 0;
		$usersAffected = 0;
		$entriesRemoved = 0;

		do {
			$qb = $dbr->newSelectQueryBuilder()
				->select( [ 'user_id', 'user_name' ] )
				->from( 'user' )
				->where( [
					$dbr->expr( 'user_id', '>', $lastId ),
					'user_editcount' => 0,
				] )
				->orderBy( 'user_id', SelectQueryBuilder::SORT_ASC )
				->limit( $batchSize );

			if ( $end !== null ) {
				$qb->andWhere( $dbr->expr( 'user_id', '<=', $end ) );
			}

			$res = $qb->caller( __METHOD__ )->fetchResultSet();

			if ( !$res->numRows() ) {
				break;
			}

			$usersById = [];
			foreach ( $res as $row ) {
				$usersById[(int)$row->user_id] = $row->user_name;
				$lastId = (int)$row->user_id;
			}
			$usersChecked += count( $usersById );

			$watchRows = $dbr->newSelectQueryBuilder()
				->select( [ 'wl_user', 'wl_namespace', 'wl_title' ] )
				->from( 'watchlist' )
				->where( [
					'wl_user' => array_keys( $usersById ),
					'wl_namespace' => [ NS_USER, NS_USER_TALK ],
				] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$matchesByUser = [];
			foreach ( $watchRows as $row ) {
				$userId = (int)$row->wl_user;
				$dbKey = strtr( $usersById[$userId], ' ', '_' );
				if ( $row->wl_title === $dbKey ) {
					$matchesByUser[$userId] = ( $matchesByUser[$userId] ?? 0 ) + 1;
				}
			}

			$doWrites = !$dryRun && $matchesByUser;
			if ( $doWrites ) {
				$this->beginTransactionRound( __METHOD__ );
			}

			foreach ( $matchesByUser as $userId => $count ) {
				$userName = $usersById[$userId];
				$dbKey = strtr( $userName, ' ', '_' );
				if ( $dryRun ) {
					$this->output( "Would remove $count entries for user $userName (ID $userId)\n" );
				} else {
					$watchedItemStore->removeWatchBatchForUser(
						new UserIdentityValue( $userId, $userName ),
						[
							PageReferenceValue::localReference( NS_USER, $dbKey ),
							PageReferenceValue::localReference( NS_USER_TALK, $dbKey ),
						]
					);
				}
				$usersAffected++;
				$entriesRemoved += $count;
			}

			if ( $doWrites ) {
				$this->commitTransactionRound( __METHOD__ );
			}

			$this->output( "Processed users up to ID $lastId\n" );
		} while ( $res->numRows() === $batchSize );

		$verb = $dryRun ? 'Would have removed' : 'Removed';
		$this->output(
			"Done. Checked $usersChecked users with 0 edits. " .
			"$verb $entriesRemoved watchlist entries from $usersAffected users.\n"
		);
	}
}

// @codeCoverageIgnoreStart
$maintClass = RemoveOwnUserPageWatchesForUsersWithoutEdits::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
