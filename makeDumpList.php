<?php

use MediaWiki\Cache\LinkBatch;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

require_once __DIR__ . '/WikimediaMaintenance.php';

class MakeDumpList extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'From a list of page titles, generate a list of titles needed to render those pages,' .
				'including templates and the pages themselves.'
		);
	}

	/** @var true[] */
	private $templates = [];

	public function execute() {
		$linkBatchFactory = MediaWikiServices::getInstance()->getLinkBatchFactory();
		$linkBatch = $linkBatchFactory->newLinkBatch();
		$batchSize = 0;
		// @phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( ( $line = fgets( STDIN ) ) !== false ) {
			$line = trim( $line );
			$title = Title::newFromText( $line );
			if ( !$title ) {
				fwrite( STDERR, "Invalid title: $line\n" );
				continue;
			}
			print $title->getPrefixedDBkey() . "\n";

			$linkBatch->addObj( $title );
			$batchSize++;
			if ( $batchSize > 100 ) {
				$this->doBatch( $linkBatch );
				$linkBatch = $linkBatchFactory->newLinkBatch();
				$batchSize = 0;
			}
		}
		if ( $batchSize ) {
			$this->doBatch( $linkBatch );
		}
		foreach ( $this->templates as $template => $unused ) {
			print "$template\n";
		}
	}

	public function doBatch( LinkBatch $linkBatch ) {
		$dbr = $this->getReplicaDB();
		$linksMigration = MediaWikiServices::getInstance()->getLinksMigration();
		$queryInfo = $linksMigration->getQueryInfo( 'templatelinks' );
		[ $nsField, $titleField ] = $linksMigration->getTitleFields( 'templatelinks' );
		$conds = [
			$linkBatch->constructSet( 'page', $dbr ),
			'page_id=tl_from'
		];
		$res = $dbr->newSelectQueryBuilder()
			->select( [ $nsField, $titleField ] )
			->from( 'page' )
			->tables( $queryInfo['tables'] )
			->where( $conds )
			->joinConds( $queryInfo['joins'] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$title = Title::makeTitle( $row->$nsField, $row->$titleField );
			$this->templates[$title->getPrefixedDBkey()] = true;
		}
	}
}

$maintClass = MakeDumpList::class;
require_once RUN_MAINTENANCE_IF_MAIN;
