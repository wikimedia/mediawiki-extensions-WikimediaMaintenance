<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

class MakeDumpList extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'From a list of page titles, generate a list of titles needed to render those pages,' .
				'including templates and the pages themselves.'
		);
	}

	private $templates = [];

	public function execute() {
		$linkBatch = new LinkBatch;
		$batchSize = 0;
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
				$linkBatch = new LinkBatch;
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

	public function doBatch( $linkBatch ) {
		$dbr = wfGetDB( DB_REPLICA );
		$conds = [
			$linkBatch->constructSet( 'page', $dbr ),
			'page_id=tl_from'
		];
		$res = $dbr->select(
			[ 'page', 'templatelinks' ],
			[ 'tl_namespace', 'tl_title' ],
			$conds,
			__METHOD__ );
		foreach ( $res as $row ) {
			$title = Title::makeTitle( $row->tl_namespace, $row->tl_title );
			$this->templates[$title->getPrefixedDBkey()] = true;
		}
	}
}

$maintClass = MakeDumpList::class;
require_once RUN_MAINTENANCE_IF_MAIN;
