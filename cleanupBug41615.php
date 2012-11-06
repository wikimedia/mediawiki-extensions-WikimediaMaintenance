<?php
require_once( __DIR__ . '/WikimediaMaintenance.php' );

// Given a binlog dump with entries like:
// 1351654498 zhwiki DELETE FROM `page` WHERE page_id = '3141968'
// 1351654498 zhwiki INSERT INTO `logging` (...)
// This will find orphaned revisions with those page IDs and fix them
// to use the current page_id under the deleted title (ns/prefix).
// If will create a new page there is there is none.
// This fixes broken restore attempts.
// The format of 'binlogdump' is very specific, see /home/asher/db/bug41649.
class CleanupBug41615 extends WikimediaMaintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Cleans up corruption caused by bug 41615";
		$this->addOption( 'fix', 'Actually update the rev_page values' );
		$this->addOption( 'logdir', "Log directory", true, true );
		$this->addOption( 'binlogdump', "Binlog dump of DELETE(page)+INSERT(logging)", true, true );
		$this->setBatchSize( 50 );
	}

	public function execute() {
		global $wgDBname;

		$logFile = $this->getOption( 'logdir' ) . "/$wgDBname";
		if ( !file_put_contents( $logFile, "STARTED " . wfTimestamp() . "\n", FILE_APPEND ) ) {
			$this->error( "Could not write to log file", 1 ); // die
		}

		# Mangle throw the log as log_comment can have newlines and such...
		$binlog = array(); // lines
		$buffer = '';
		$inQuote = false;
		$binraw = trim( file_get_contents( $this->getOption( 'binlogdump' ) ) );
		for ( $i=0; $i < strlen( $binraw ); ++$i ) {
			$ch = $binraw[$i];
			if ( $ch === "'" && ( $i <= 0 || $binraw[$i-1] !== "\\" ) ) {
				$inQuote = !$inQuote; // unescaped quote
			}
			if ( $ch === "\n" && !$inQuote ) {
				$binlog[] = $buffer;
				$buffer = '';
			} else {
				$buffer .= $ch;
			}
		}
		if ( $buffer !== '' ) {
			$binlog[] = $buffer;
		}

		if ( ( count( $binlog ) % 2 ) != 0 ) {
			$this->error( "Binlog dump entries not matched up.\n", 1 );
		}
		$binlog = array_chunk( $binlog, 2 ); // there should be pairs of corresponding logs

		$deletedPages = array();
		foreach ( $binlog as $entries ) {
			list( $dEntry, $iEntry ) = $entries;
			$m = array();
			// 1351692955 itwiki DELETE /* WikiPage::doDeleteArticleReal Guidomac */
			// FROM `page` WHERE page_id = '4258611'
			if ( !preg_match( "!^\d+ (\w+) DELETE .* WHERE page_id = '(\d+)'!", $dEntry, $m ) ) {
				$this->error( "Could not parse '$dEntry'.", 1 );
			}
			$info = array( 'wiki' => $m[1], 'page_id' => $m[2] );
			// 1351692955 itwiki INSERT /* ManualLogEntry::insert Guidomac */  INTO `logging`
			// (log_id,log_type,log_action,log_timestamp,log_user,log_user_text,log_namespace,log_title,log_page,log_comment,log_params)
			// VALUES (NULL,'delete','delete','20121031141555','276491','Guidomac','0','Doesn\'t_Matter','0','([[WP:IMMEDIATA|C1]]) Pagina o sottopagina vuota, di prova, senza senso o tautologica','a:0:{}')
			$et = "(?:[^']|\')*"; // single-quote escaped item
			if ( !preg_match( "! VALUES \(NULL,'delete','delete','\d+','\d+','$et','(\d+)','($et)','\d','$et','$et'\)!m", $iEntry, $m ) ) {
				$this->error( "Could not parse '$iEntry'.", 1 );
			}
			$info['log_namespace'] = $m[1];
			$info['log_title'] = str_replace( "\'", "'", $m[2] ); // unescape
			$deletedPages[] = $info;
		}

		if ( !$this->hasOption( 'fix' ) ) {
			$this->output( "Parsed logs:\n" . print_r( $deletedPages, true ) . "\n" );
		}

		$dbw = wfGetDB( DB_MASTER );
		foreach ( $deletedPages as $info ) {
			if ( $info['wiki'] !== $wgDBname ) {
				continue; // for some other wiki
			} elseif ( Title::newFromId( $info['page_id'] ) ) {
				$this->output( "Page {$info['page_id']} exists.\n" );
				continue; // sanity check
			}

			$title = Title::makeTitleSafe( $info['log_namespace'], $info['log_title'] );
			$this->output( "Inspecting {$title->getPrefixedText()}\n" );

			$count = $dbw->selectField( 'revision',
				'COUNT(*)', array( 'rev_page' => $info['page_id'] ) );

			if ( $count > 0 ) { // number of affected revs for this page ID
				$article = WikiPage::factory( $title );
				$lastRev = Revision::newFromRow( $dbw->selectRow( 'revision',
					'*',
					array( 'rev_page' => $info['page_id'] ),
					__METHOD__,
					array( 'ORDER BY' => 'rev_timestamp DESC' )
				) );
				// Revisions were restored using ar_page_id, not the new page created on restore.
				// We need to move this to the new page_id created for the old title.
				$newID = $title->getArticleId( Title::GAID_FOR_UPDATE );
				// Create an article with the title if not exists
				if ( $newID <= 0 ) {
					if ( $this->hasOption( 'fix' ) ) {
						$newID = $article->insertOn( $dbw ); // make new page
					} else {
						$newID = '<newpageid>'; // dry run, don't make a new page
					}
				}
				// Log the upcoming UPDATE
				if ( !file_put_contents( $logFile, "{$info['page_id']} => $newID\n", FILE_APPEND ) ) {
					$this->error( "Could not write to log file", 1 ); // die
				}
				if ( $this->hasOption( 'fix' ) ) {
					$dbw->update( 'revision',
						array( 'rev_page' => $newID ),
						array( 'rev_page' => $info['page_id'] ),
						__METHOD__
					);
					$article->updateIfNewerOn( $dbw, $lastRev ); // fix page_latest
				}
				$this->output( "UPDATE rev_page {$info['page_id']} => $newID\n [$count rows]\n" );
			}
		}
	}
}

$maintClass = "CleanupBug41615";
require_once( RUN_MAINTENANCE_IF_MAIN );
