<?php

// @codeCoverageIgnoreStart
require_once __DIR__ . '/WikimediaMaintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;

class T389026 extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addOption( 'start', 'start content_id', false, true, 's' );
		$this->addOption( 'dry-run', 'Don\'t modify any rows' );
		$this->addDescription( 'Repopulate empty content_sha1 field from revision and archive table' );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		if ( WikiMap::getCurrentWikiId() == 'commonswiki' ) {
			$this->fatalError( 'This script can not be run on commonswiki since it uses more than one slot.' );
		}

		$db = $this->getDB();
		$batchSize = $this->getBatchSize();
		$dryRun = $this->getOption( 'dry-run', false );

		$currentId = (int)$this->getOption( 'start', 0 );

		while ( $currentId < $batchSize ) {
			$res = $db->newSelectQueryBuilder()
				->select( [ 'slot_revision_id', 'content_id' ] )
				->from( 'slots' )
				->join( 'content', null, 'content_id = slot_content_id' )
				->where( [
					'content_sha1' => '',
					$db->expr( 'content_id', '>=', $currentId ),
					$db->expr( 'content_id', '<', $currentId + $batchSize ),
				] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $res as $row ) {
				$revSha1 = $db->newSelectQueryBuilder()
					->select( 'rev_sha1' )
					->from( 'revision' )
					->where( [ 'rev_id' => $row->slot_revision_id ] )
					->caller( __METHOD__ )
					->fetchField();

				if ( !$revSha1 ) {
					$arSha1 = $db->newSelectQueryBuilder()
						->select( 'ar_sha1' )
						->from( 'archive' )
						->where( [ 'ar_rev_id' => $row->slot_revision_id ] )
						->caller( __METHOD__ )
						->fetchField();
				}

				if ( !$revSha1 && !$arSha1 ) {
					$this->output( "Sha1 value for {$row->slot_revision_id} is missing.\n" );
					continue;
				}

				if ( $dryRun ) {
					$this->output( "Would update {$row->content_id} to " . ( $revSha1 ?? $arSha1 ) . "\n" );
				} else {
					$db->newUpdateQueryBuilder()
						->update( 'content' )
						->set( [ 'content_sha1' => $revSha1 ?? $arSha1 ] )
						->where( [ 'content_id' => $row->content_id ] )
						->caller( __METHOD__ )
						->execute();
				}
			}

			$this->waitForReplication();
			$currentId += $batchSize;
			$this->output( "Processed {$currentId} rows.\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = T389026::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
