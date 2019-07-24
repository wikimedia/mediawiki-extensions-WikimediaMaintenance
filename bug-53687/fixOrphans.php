<?php

require_once __DIR__ . '/../WikimediaMaintenance.php';

class FixOrphans extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addArg( 'list-file', 'The list file generated by find-orphans.sql' );
		$this->addOption( 'dry-run', 'dry run' );
	}

	public function execute() {
		$fileName = $this->getArg( 0 );
		$f = fopen( $fileName, 'r' );
		if ( !$f ) {
			$this->fatalError( "Unable to open list file \"$fileName\"" );
		}
		$lineNumber = 0;
		$dryRun = $this->getOption( 'dry-run' );
		if ( $dryRun ) {
			$this->output( "Dry run mode\n" );
		}
		$dbw = wfGetDB( DB_MASTER );

		$verifyPairs = [
			'ar_timestamp' => 'rev_timestamp',
			'ar_minor_edit' => 'rev_minor_edit',
			'ar_text_id' => 'rev_text_id',
			'ar_deleted' => 'rev_deleted',
			'ar_len' => 'rev_len',
			'ar_page_id' => 'rev_page',
			'ar_parent_id' => 'rev_parent_id',
			'ar_sha1' => 'rev_sha1',
			'ar_actor' => 'revactor_actor',
			'ar_comment_id' => 'revcomment_comment_id',
		];

		$commentStore = CommentStore::getStore();
		$commentQuery = $commentStore->getJoin( 'rev_comment' );
		$actorMigration = ActorMigration::newMigration();
		$actorQuery = $actorMigration->getJoin( 'rev_user' );

		while ( !feof( $f ) ) {
			$line = fgets( $f );
			$lineNumber++;
			if ( $line === false ) {
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( $line === '' ) {
				continue;
			}
			$parts = explode( "\t", $line );
			if ( count( $parts ) < 7 ) {
				$this->error( "XXX: ERROR Invalid line $lineNumber\n" );
				continue;
			}
			$info = array_combine( [ 'up_page', 'up_timestamp', 'log_namespace',
				'log_title', 'rev_id', 'ar_rev_match', 'ar_text_match' ], $parts );
			$revId = $info['rev_id'];

			$this->beginTransaction( $dbw, __METHOD__ );
			$revRow = $dbw->selectRow(
				[ 'revision' ] + $commentQuery['tables'] + $actorQuery['tables'],
				[ $dbw->tableName( 'revision' ) . '.*' ] + $commentQuery['fields'] + $actorQuery['fields'],
				[ 'rev_id' => $revId ],
				__METHOD__,
				[ 'FOR UPDATE' ],
				$commentQuery['joins'] + $actorQuery['joins']
			);
			if ( !$revRow ) {
				$this->error( "$revId: ERROR revision row has disappeared!" );
				$this->commitTransaction( $dbw, __METHOD__ );
				continue;
			}

			$arRow = $dbw->selectRow( 'archive', '*', [ 'ar_rev_id' => $revId ],
				__METHOD__, [ 'FOR UPDATE' ] );
			$pageRow = $dbw->selectRow( 'page', '*', [ 'page_id' => $revRow->rev_page ],
				__METHOD__, [ 'FOR UPDATE' ] );

			if ( $pageRow ) {
				// rev_page is somehow connected to a valid page row
				// This probably can't happen, but we want to be extra sure we are not
				// deleting live revisions
				if ( $arRow ) {
					$this->output( "$revId: page still connected! " .
						"Removing duplicate archive row.\n" );
					$action = 'remove-archive';
				} else {
					$this->output( "$revId: seems normal! Taking no action.\n" );
					$action = 'none';
				}
			} elseif ( $arRow ) {
				// Both the revision and archive rows exist
				// The revision row is not connected to a page and so is
				// unreachable. So assuming both contain the same data, it is
				// appropriate to delete the revision row, leaving the archive
				// row as the sole means of accessing the text ID
				$action = 'remove-revision';
				foreach ( $verifyPairs as $arField => $revField ) {
					if ( $arRow->$arField !== $revRow->$revField ) {
						$this->error( "$revId: ERROR mismatch between archive and revision " .
							"rows in field $arField/$revField" );
						$action = 'none';
						break;
					}
				}
				if ( $action !== 'none' ) {
					$this->output( "$revId: verified that orphan revision row matches " .
						"existing archive row. Deleting revision row.\n" );
				}
			} else {
				// Only an orphaned revision row exists, so there is no way to access
				// the revision via the UI. The assumption is that a deletion failed
				// to complete, so we create a valid archive row and delete the invalid
				// revision row.
				if ( $info['log_namespace'] === 'NULL' || $info['log_title'] === 'NULL' ) {
					$this->error( "$revId: ERROR no log row, unable to determine title\n" );
					$action = 'none';
				} else {
					$this->output( "$revId: moving orphaned revision row to archive\n" );
					$action = 'move-revision';
				}
			}

			if ( $dryRun ) {
				$this->commitTransaction( $dbw, __METHOD__ );
				continue;
			}

			if ( $action === 'remove-archive' ) {
				$dbw->delete( 'archive', [ 'ar_rev_id' => $revId ], __METHOD__ );
			} elseif ( $action === 'remove-revision' ) {
				$dbw->delete( 'revision', [ 'rev_id' => $revId ], __METHOD__ );
				$dbw->delete( 'revision_comment_temp', [ 'revcomment_rev' => $revId ], __METHOD__ );
				$dbw->delete( 'revision_actor_temp', [ 'revactor_rev' => $revId ], __METHOD__ );
			} elseif ( $action === 'move-revision' ) {
				$comment = $commentStore->getComment( 'rev_comment', $revRow );
				$user = User::newFromAnyId( $revRow->rev_user, $revRow->rev_user_text, $revRow->rev_actor );
				$dbw->insert( 'archive',
					[
						'ar_namespace'  => $info['log_namespace'],
						'ar_title'      => $info['log_title'],
						'ar_timestamp'  => $revRow->rev_timestamp,
						'ar_minor_edit' => $revRow->rev_minor_edit,
						'ar_rev_id'     => $revId,
						'ar_parent_id'  => $revRow->rev_parent_id,
						'ar_text_id'    => $revRow->rev_text_id,
						'ar_text'       => '',
						'ar_flags'      => '',
						'ar_len'        => $revRow->rev_len,
						'ar_page_id'    => $revRow->rev_page,
						'ar_deleted'    => $revRow->rev_deleted,
						'ar_sha1'       => $revRow->rev_sha1,
					] + $commentStore->insert( $dbw, 'ar_comment', $comment )
						+ $actorMigration->getInsertValues( $dbw, 'ar_user', $user ),
					__METHOD__ );
				$dbw->delete( 'revision', [ 'rev_id' => $revId ], __METHOD__ );
				$dbw->delete( 'revision_comment_temp', [ 'revcomment_rev' => $revId ], __METHOD__ );
				$dbw->delete( 'revision_actor_temp', [ 'revactor_rev' => $revId ], __METHOD__ );
			}
			$this->commitTransaction( $dbw, __METHOD__ );

			if ( $lineNumber % 100 == 1 ) {
				wfWaitForSlaves();
			}
		}
	}
}

$maintClass = FixOrphans::class;
require_once RUN_MAINTENANCE_IF_MAIN;
