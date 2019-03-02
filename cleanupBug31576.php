<?php
require_once __DIR__ . '/WikimediaMaintenance.php';

class CleanupBug31576 extends Maintenance {

	protected $batchsize;

	protected $processed = [];

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Cleans up templatelinks corruption caused by https://bugzilla.wikimedia.org/show_bug.cgi?id=31576";
		$this->addOption( 'batchsize', 'Number of rows to process in one batch. Default: 50', false, true );
	}

	public function execute() {
		$this->batchsize = $this->getOption( 'batchsize', 50 );
		$variableIDs = MagicWord::getVariableIDs();
		foreach ( $variableIDs as $id ) {
			$magic = MagicWord::get( $id );
			foreach ( $magic->getSynonyms() as $synonym ) {
				$this->processSynonym( $synonym );
			}
		}
		$this->output( "All done\n" );
	}

	public function processSynonym( $synonym ) {
		$dbr = wfGetDB( DB_REPLICA );
		$pCount = 0;
		$vCount = 0;
		$this->output( "Fixing pages with template links to $synonym ...\n" );
		$from = null;
		while ( true ) {
			$where = [
				'tl_namespace' => NS_TEMPLATE,
				'tl_title ' . $dbr->buildLike( $synonym, $dbr->anyString() )
			];
			if ( $from !== null ) {
				$where[] = 'tl_from > ' . $dbr->addQuotes( $from );
				$from = null;
			}
			$res = $dbr->select( 'templatelinks', [ 'tl_title', 'tl_from' ],
				$where,
				__METHOD__,
				[ 'ORDER BY' => [ 'tl_title', 'tl_from' ], 'LIMIT' => $this->batchsize ]
			);
			if ( $dbr->numRows( $res ) == 0 ) {
				// No more rows, we're done
				break;
			}

			foreach ( $res as $row ) {
				$vCount++;
				if ( isset( $this->processed[$row->tl_from] ) ) {
					// We've already processed this page, skip it
					continue;
				}
				RefreshLinks::fixLinksFromArticle( $row->tl_from );
				$this->processed[$row->tl_from] = true;
				$pCount++;
			}
			if ( isset( $row ) ) {
				$from = $row->tl_from;
			}
			$this->output( "{$pCount}/{$vCount} pages processed\n" );
			wfWaitForSlaves();
		}
	}

}

$maintClass = CleanupBug31576::class;
require_once RUN_MAINTENANCE_IF_MAIN;
