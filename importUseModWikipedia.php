<?php

/**
 * A script to read a dump of the English Wikipedia from the UseModWiki period, and to
 * generate an XML dump in MediaWiki format.
 *
 * Some relevant code was ported from UseModWiki 0.92.
 *
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

use UtfNormal\Utils;

class ImportUseModWikipedia extends Maintenance {
	public $encodeMap, $decodeMap;

	public $deepRenames = [
		'JimboWales' => 983862286,
		'TexaS' => 983918410,
		'HistoryOfUnitedStatesTalk' => 984795423,
		'MetallicA' => 985128533,
		'PythagoreanTheorem' => 985225545,
		'TheCanonofScripture' => 985368223,
		'TaoTehChing' => 985368222,
		// 'TheMostRemarkableFormulaInTheWorld' => 985368221,
		'TheRecorder' => 985368220,
		'GladstoneOregon' => 985368219,
		'PacificBeach' => '?',
		'AaRiver' => '?',
	];

	public $replacements = [];

	public $renameTextLinksOps = [
		983846265 => [
			'TestIgnore' => 'IgnoreTest',
		],
		983848080 => [
			'UnitedLocomotiveWorks' => 'Atlas Shrugged/United Locomotive Works'
		],
		983856376 => [
			'WikiPedia' => 'Wikipedia',
		],
		983896152 => [
			'John_F_Kennedy' => 'John_F._Kennedy',
		],
		983905871 => [
			'LarrySanger' => 'Larry_Sanger'
		],
		984697068 => [
			'UnitedStates' => 'United States',
		],
		984792748 => [
			'LibertarianisM' => 'Libertarianism'
		],
		985327832 => [
			'AnarchisM' => 'Anarchism',
		],
		985290063 => [
			'HistoryOfUnitedStatesDiscussion' => 'History_Of_United_States_Discussion'
		],
		985290091 => [
			'BritishEmpire' => 'British Empire'
		],
		/*
		985468958 => array(
			'ScienceFiction' => 'Science fiction',
		),*/
	];

	/**
	 * @var string[] Hack for observed substitution issues
	 */
	public $skipSelfSubstitution = [
		'Pythagorean_Theorem',
		'The_Most_Remarkable_Formula_In_The_World',
		'Wine',
	];

	public $unixLineEndingsOps = [
		987743732 => 'Wikipedia_FAQ'
	];

	public $replacementsDone = [];

	/** @var array[] */
	public $moveLog = [];
	public $moveDests = [];
	public $revId;

	public $rc = [];
	public $textCache = [];
	public $blacklist = [];

	public $FS, $FS1, $FS2, $FS3;
	public $FreeLinkPattern, $UrlPattern, $LinkPattern, $InterLinkPattern;

	/** @var array */
	public $cp1252Table = [
0x80 => 0x20ac,
0x81 => 0x0081,
0x82 => 0x201a,
0x83 => 0x0192,
0x84 => 0x201e,
0x85 => 0x2026,
0x86 => 0x2020,
0x87 => 0x2021,
0x88 => 0x02c6,
0x89 => 0x2030,
0x8a => 0x0160,
0x8b => 0x2039,
0x8c => 0x0152,
0x8d => 0x008d,
0x8e => 0x017d,
0x8f => 0x008f,
0x90 => 0x0090,
0x91 => 0x2018,
0x92 => 0x2019,
0x93 => 0x201c,
0x94 => 0x201d,
0x95 => 0x2022,
0x96 => 0x2013,
0x97 => 0x2014,
0x98 => 0x02dc,
0x99 => 0x2122,
0x9a => 0x0161,
0x9b => 0x203a,
0x9c => 0x0153,
0x9d => 0x009d,
0x9e => 0x017e,
0x9f => 0x0178 ];

	/** @var string */
	private $articleFileName;

	/** @var string */
	private $patchFileName;

	/** @var string */
	private $dataDir;

	/** @var resource */
	private $outFile;

	/** @var int */
	private $numGoodRevs;

	/** @var int */
	private $numRevs;

	/** @var array */
	private $saveUrl;

	/** @var array */
	private $linkList;

	/** @var string */
	private $old;

	/** @var string */
	private $new;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'datadir', 'the value of $DataDir from wiki.cgi', true, true );
		$this->addOption( 'outfile', 'the name of the output XML file', true, true );
		$this->initLinkPatterns();

		$this->encodeMap = $this->decodeMap = [];

		for ( $source = 0; $source <= 0xff; $source++ ) {
			if ( isset( $this->cp1252Table[$source] ) ) {
				$dest = $this->cp1252Table[$source];
			} else {
				$dest = $source;
			}
			$sourceChar = chr( $source );
			$destChar = Utils::codepointToUtf8( $dest );
			$this->encodeMap[$sourceChar] = $destChar;
			$this->decodeMap[$destChar] = $sourceChar;
		}
	}

	private function initLinkPatterns() {
		# Field separators are used in the URL-style patterns below.
		$this->FS  = "\xb3";      # The FS character is a superscript "3"
		$this->FS1 = $this->FS . "1";   # The FS values are used to separate fields
		$this->FS2 = $this->FS . "2";   # in stored hashtables and other data structures.
		$this->FS3 = $this->FS . "3";   # The FS character is not allowed in user data.

		$UpperLetter = "[A-Z";
		$LowerLetter = "[a-z";
		$AnyLetter   = "[A-Za-z";
		$AnyLetter .= "_0-9";
		$UpperLetter .= "]";
$LowerLetter .= "]";
$AnyLetter .= "]";

		# Main link pattern: lowercase between uppercase, then anything
		$LpA = $UpperLetter . "+" . $LowerLetter . "+" . $UpperLetter
			. $AnyLetter . "*";
		# Optional subpage link pattern: uppercase, lowercase, then anything
		$LpB = $UpperLetter . "+" . $LowerLetter . "+" . $AnyLetter . "*";

		# Loose pattern: If subpage is used, subpage may be simple name
		$this->LinkPattern = "((?:(?:$LpA)?\\/$LpB)|$LpA)";
		$QDelim = '(?:"")?';     # Optional quote delimiter (not in output)
		$this->LinkPattern .= $QDelim;

		# Inter-site convention: sites must start with uppercase letter
		# (Uppercase letter avoids confusion with URLs)
		$InterSitePattern = $UpperLetter . $AnyLetter . "+";
		$this->InterLinkPattern = "((?:$InterSitePattern:[^\\]\\s\"<>{$this->FS}]+)$QDelim)";

		$AnyLetter = "[-,. _0-9A-Za-z]";
		$this->FreeLinkPattern = "($AnyLetter+)";
		$this->FreeLinkPattern = "((?:(?:$AnyLetter+)?\\/)?$AnyLetter+)";
		$this->FreeLinkPattern .= $QDelim;

		# Url-style links are delimited by one of:
		#   1.  Whitespace                           (kept in output)
		#   2.  Left or right angle-bracket (< or >) (kept in output)
		#   3.  Right square-bracket (])             (kept in output)
		#   4.  A single double-quote (")            (kept in output)
		#   5.  A $FS (field separator) character    (kept in output)
		#   6.  A double double-quote ("")           (removed from output)

		$UrlProtocols = "http|https|ftp|afs|news|nntp|mid|cid|mailto|wais|"
			. "prospero|telnet|gopher";
		$UrlProtocols .= '|file';
		$this->UrlPattern = "((?:(?:$UrlProtocols):[^\\]\\s\"<>{$this->FS}]+)$QDelim)";
		$ImageExtensions = "(gif|jpg|png|bmp|jpeg)";
		$RFCPattern = "RFC\\s?(\\d+)";
		$ISBNPattern = "ISBN:?([0-9- xX]{10,})";
	}

	public function execute() {
		$this->articleFileName = '/tmp/importUseMod.' . mt_rand( 0, 0x7ffffff ) . '.tmp';
		$this->patchFileName = '/tmp/importUseMod.' . mt_rand( 0, 0x7ffffff ) . '.tmp';
		$this->dataDir = $this->getOption( 'datadir' );
		$this->outFile = fopen( $this->getOption( 'outfile' ), 'w' );
		if ( !$this->outFile ) {
			echo "Unable to open output file\n";
			return true;
		}
		$this->writeXmlHeader();
		$this->readRclog();
		$this->writeMoveLog();
		$this->writeRevisions();
		$this->reconcileCurrentRevs();
		$this->writeXmlFooter();
		unlink( $this->articleFileName );
		unlink( $this->patchFileName );
		return false;
	}

	private function writeXmlHeader() {
		// @phpcs:disable Generic.Files.LineLength
		fwrite( $this->outFile, <<<EOT
<mediawiki xmlns="http://www.mediawiki.org/xml/export-0.3/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.mediawiki.org/xml/export-0.3/ http://www.mediawiki.org/xml/export-0.3.xsd" version="0.3" xml:lang="en">
  <siteinfo>
    <sitename>Wikipedia</sitename>
    <base>http://www.wikipedia.com/</base>
    <generator>MediaWiki 1.18alpha importUseModWikipedia.php</generator>
    <case>case-sensitive</case>
    <namespaces>
      <namespace key="0" />
    </namespaces>
  </siteinfo>

EOT
		);
		// @phpcs:enable Generic.Files.LineLength
	}

	private function writeXmlFooter() {
		fwrite( $this->outFile, "</mediawiki>\n" );
	}

	private function readRclog() {
		$rcFile = fopen( "{$this->dataDir}/rclog", 'r' );
		while ( $line = fgets( $rcFile ) ) {
			$bits = explode( $this->FS3, $line );
			if ( count( $bits ) !== 7 ) {
				echo "Error reading rclog\n";
				return;
			}
			$params = [
				'timestamp' => $bits[0],
				'rctitle' => $bits[1],
				'summary' => $bits[2],
				'minor' => $bits[3],
				'host' => $bits[4],
				'kind' => $bits[5],
				'extra' => []
			];
			$extraList = explode( $this->FS2, $bits[6] );

			for ( $i = 0; $i < count( $extraList ); $i += 2 ) {
				$params['extra'][$extraList[$i]] = $extraList[$i + 1];
			}
			$this->rc[$params['timestamp']][] = $params;
		}
	}

	private function writeMoveLog() {
		$this->moveLog = [];
		$deepRenames = $this->deepRenames;
		echo "Calculating move log...\n";
		$this->processDiffFile( [ $this, 'moveLogCallback' ] );

		// We have the timestamp intervals, now make a guess at the actual timestamp
		// @phan-suppress-next-line PhanEmptyForeach Filled by callback
		foreach ( $this->moveLog as $newTitle => $params ) {
			// Is there a time specified?
			$drTime = false;
			if ( isset( $deepRenames[$params['old']] ) ) {
				$drTime = $deepRenames[$params['old']];
				if ( $drTime !== '?' ) {
					if ( ( !isset( $params['endTime'] ) || $drTime < $params['endTime'] )
						&& $drTime > $params['startTime'] ) {
						$this->moveLog[$newTitle]['timestamp'] = $drTime;
						$this->moveLog[$newTitle]['deep'] = true;

						echo "{$params['old']} -> $newTitle at $drTime\n";
						unset( $deepRenames[$params['old']] );
						continue;
					} else {
						echo "WARNING: deep rename time invalid: {$params['old']}\n";
						unset( $deepRenames[$params['old']] );
					}
				}
			}

			// Guess that it is one second after the last edit to the page before it was moved
			$this->moveLog[$newTitle]['timestamp'] = $params['startTime'] + 1;
			if ( $drTime === '?' ) {
				$this->moveLog[$newTitle]['deep'] = true;
				unset( $deepRenames[$params['old']] );
			}
			if ( isset( $params['endTime'] ) ) {
				$this->printLatin1( "{$params['old']} -> $newTitle between " .
					"{$params['startTime']} and {$params['endTime']}\n" );
			} else {
				$this->printLatin1( "{$params['old']} -> $newTitle after " .
					"{$params['startTime']}\n" );
			}
		}

		// Write the move log to the XML file
		$id = 1;
		foreach ( $this->moveLog as $newTitle => $params ) {
			$out = "<logitem>\n" .
				$this->element( 'id', $id++ ) .
				$this->element( 'timestamp', wfTimestamp( TS_ISO_8601, $params['timestamp'] ) ) .
				"<contributor>\n" .
				$this->element( 'username', 'UseModWiki admin' ) .
				"</contributor>" .
				$this->element( 'type', 'move' ) .
				$this->element( 'action', 'move' ) .
				// @phan-suppress-next-line PhanTypeInvalidDimOffset
				$this->element( 'logtitle', $params['old'] ) .
				"<params xml:space=\"preserve\">" .
				htmlspecialchars( $this->encode( "{$newTitle}\n1" ) ) .
				"</params>\n" .
				"</logitem>\n";
			fwrite( $this->outFile, $out );
		}

		// Check for remaining deep rename entries
		if ( $deepRenames ) {
			echo "WARNING: the following entries in \$this->deepRenames are " .
				"invalid, since no such move exists:\n" .
				implode( "\n", array_keys( $deepRenames ) ) .
				"\n\n";
		}
	}

	private function element( $name, $value ) {
		return "<$name>" . htmlspecialchars( $this->encode( $value ) ) . "</$name>\n";
	}

	private function moveLogCallback( $entry ) {
		$rctitle = $entry['rctitle'];
		$title = $entry['title'];
		$this->moveDests[$rctitle] = $title;

		if ( $rctitle === $title ) {
			if ( isset( $this->moveLog[$rctitle] )
				&& !isset( $this->moveLog[$rctitle]['endTime'] ) ) {
				// This is the latest time that the page could have been moved
				$this->moveLog[$rctitle]['endTime'] = $entry['timestamp'];
			}
		} else {
			if ( !isset( $this->moveLog[$rctitle] ) ) {
				// Initialise the move log entry
				$this->moveLog[$rctitle] = [
					'old' => $title
				];
			}
			// Update the earliest time the page could have been moved
			$this->moveLog[$rctitle]['startTime'] = $entry['timestamp'];
		}
	}

	private function writeRevisions() {
		$this->numGoodRevs = 0;
		$this->revId = 1;
		$this->processDiffFile( [ $this, 'revisionCallback' ] );
		echo "\n\nImported {$this->numGoodRevs} out of {$this->numRevs}\n";
	}

	private function revisionCallback( $params ) {
		$title = $params['rctitle'];
		$editTime = $params['timestamp'];

		if ( isset( $this->blacklist[$title] ) ) {
			return;
		}
		$this->doPendingOps( $editTime );

		$origText = $this->getText( $title );
		$text = $this->patch( $origText, $params['diff'] );
		if ( $text === false ) {
			echo "$editTime $title attempting resolution...\n";
			$linkSubstitutes = $this->resolveFailedDiff( $origText, $params['diff'] );
			if ( !$linkSubstitutes ) {
				$this->printLatin1( "$editTime $title DIFF FAILED\n" );
				$this->blacklist[$title] = true;
				return;
			}
			$this->printLatin1( "$editTime $title requires substitutions:\n" );
			$time = $editTime - 1;
			foreach ( $linkSubstitutes as $old => $new ) {
				$this->printLatin1( "SUBSTITUTE $old -> $new\n" );
				$this->renameTextLinks( $old, $new, $time-- );
			}
			$origText = $this->getText( $title );
			$text = $this->patch( $origText, $params['diff'] );
			if ( $text === false ) {
				$this->printLatin1( "$editTime $title STILL FAILS!\n" );
				$this->blacklist[$title] = true;
				return;
			}

			echo "\n";
		}

		$params['text'] = $text;
		$this->saveRevision( $params );
		$this->numGoodRevs++;
		# $this->printLatin1( "$editTime $title\n" );
	}

	private function doPendingOps( $editTime ) {
		foreach ( $this->moveLog as $newTitle => $entry ) {
			if ( $entry['timestamp'] <= $editTime ) {
				unset( $this->moveLog[$newTitle] );
				if ( isset( $entry['deep'] ) ) {
					$this->renameTextLinks( $entry['old'], $newTitle, $entry['timestamp'] );
				}
			}
		}

		foreach ( $this->renameTextLinksOps as $renameTime => $replacements ) {
			if ( $editTime >= $renameTime ) {
				foreach ( $replacements as $old => $new ) {
					$this->printLatin1( "SUBSTITUTE $old -> $new\n" );
					$this->renameTextLinks( $old, $new, $renameTime );
				}
				unset( $this->renameTextLinksOps[$renameTime] );
			}
		}

		foreach ( $this->unixLineEndingsOps as $fixTime => $title ) {
			if ( $editTime >= $fixTime ) {
				$this->printLatin1( "$fixTime $title FIXING LINE ENDINGS\n" );
				$text = $this->getText( $title );
				$text = str_replace( "\r", '', $text );
				$this->saveRevision( [
					'rctitle' => $title,
					'timestamp' => $fixTime,
					'extra' => [ 'name' => 'UseModWiki admin' ],
					'text' => $text,
					'summary' => 'Fixing line endings',
				] );
				unset( $this->unixLineEndingsOps[$fixTime] );
			}
		}
	}

	private function patch( $source, $diff ) {
		file_put_contents( $this->articleFileName, $source );
		file_put_contents( $this->patchFileName, $diff );
		$error = wfShellExec(
			wfEscapeShellArg(
				'patch',
				'-n',
				'-r', '-',
				'--no-backup-if-mismatch',
				'--binary',
				$this->articleFileName,
				$this->patchFileName
			) . ' 2>&1',
			$status
		);
		$text = file_get_contents( $this->articleFileName );
		if ( $status || $text === false ) {
			return false;
		} else {
			return $text;
		}
	}

	private function resolveFailedDiff( $origText, $diff ) {
		$context = [];
		$diffLines = explode( "\n", $diff );
		for ( $i = 0; $i < count( $diffLines ); $i++ ) {
			$diffLine = $diffLines[$i];
			if ( !preg_match( '/^(\d+)(?:,\d+)?[acd]\d+(?:,\d+)?$/', $diffLine, $m ) ) {
				continue;
			}

			$sourceIndex = intval( $m[1] );
			$i++;
			while ( $i < count( $diffLines ) && substr( $diffLines[$i], 0, 1 ) === '<' ) {
				$context[$sourceIndex - 1] = substr( $diffLines[$i], 2 );
				$sourceIndex++;
				$i++;
			}
			$i--;
		}

		$changedLinks = [];
		$origLines = explode( "\n", $origText );
		foreach ( $context as $i => $contextLine ) {
			$origLine = $origLines[$i] ?? '';
			if ( $contextLine === $origLine ) {
				continue;
			}
			$newChanges = $this->resolveTextChange( $origLine, $contextLine );
			if ( is_array( $newChanges ) ) {
				$changedLinks += $newChanges;
			} else {
				echo "Resolution failure on line " . ( $i + 1 ) . "\n";
				$this->printLatin1( $newChanges );
			}
		}

		return $changedLinks;
	}

	private function resolveTextChange( $source, $dest ) {
		$changedLinks = [];
		$sourceLinks = $this->getLinkList( $source );
		$destLinks = $this->getLinkList( $dest );
		$newLinks = array_diff( $destLinks, $sourceLinks );
		$removedLinks = array_diff( $sourceLinks, $destLinks );

		// Match up the removed links with the new links
		foreach ( $newLinks as $newLink ) {
			$minDistance = 100000000;
			$bestRemovedLink = false;
			foreach ( $removedLinks as $removedLink ) {
				$editDistance = levenshtein( $newLink, $removedLink );
				if ( $editDistance < $minDistance ) {
					$minDistance = $editDistance;
					$bestRemovedLink = $removedLink;
				}
			}
			if ( $bestRemovedLink !== false ) {
				$changedLinks[$bestRemovedLink] = $newLink;
				$newLinks = array_diff( $newLinks, [ $newLink ] );
				$removedLinks = array_diff( $removedLinks, [ $bestRemovedLink ] );
			}
		}

		$proposal = $source;
		foreach ( $changedLinks as $removedLink => $newLink ) {
			$proposal = $this->substituteTextLinks( $removedLink, $newLink, $proposal );
		}
		if ( $proposal !== $dest ) {
			// Resolution failed
			$msg = "Source line: $source\n" .
				"Source links: " . implode( ', ', $sourceLinks ) . "\n" .
				"Context line: $dest\n" .
				"Context links: " . implode( ', ', $destLinks ) . "\n" .
				"Proposal: $proposal\n";
			return $msg;
		}
		return $changedLinks;
	}

	private function processDiffFile( $callback ) {
		$diffFile = fopen( "{$this->dataDir}/diff_log", 'r' );

		$delimiter = "------\n";
		file_put_contents( $this->articleFileName, "Describe the new page here.\n" );

		$line = fgets( $diffFile );
		$lineNum = 1;
		if ( $line !== $delimiter ) {
			echo "Invalid diff file\n";
			return false;
		}
		$lastReportLine = 0;
		$this->numRevs = 0;

		while ( true ) {
			$line = fgets( $diffFile );
			$lineNum++;
			if ( $line === false ) {
				break;
			}
			if ( $lineNum > $lastReportLine + 1000 ) {
				$lastReportLine = $lineNum;
				fwrite( STDERR, "$lineNum       \r" );
				fflush( STDERR );
			}
			$line = trim( $line );
			if ( !preg_match( '/^([^|]+)\|(\d+)$/', $line, $matches ) ) {
				echo "Invalid header on line $lineNum\n";
				return true;
			}
			list( , $title, $editTime ) = $matches;

			$diff = '';
			$diffStartLine = $lineNum;
			while ( true ) {
				$line = fgets( $diffFile );
				$lineNum++;
				if ( $line === $delimiter ) {
					break;
				}
				if ( $line === false ) {
					break 2;
				}
				$diff .= $line;
			}

			$this->numRevs++;

			if ( !isset( $this->rc[$editTime] ) ) {
				$this->printLatin1( "$editTime $title DELETED, skipping\n" );
				continue;
			}

			if ( count( $this->rc[$editTime] ) == 1 ) {
				$params = $this->rc[$editTime][0];
			} else {
				$params = false;
				$candidates = '';
				foreach ( $this->rc[$editTime] as $rc ) {
					if ( $rc['rctitle'] === $title ) {
						$params = $rc;
						break;
					}
					if ( $candidates === '' ) {
						$candidates = $rc['rctitle'];
					} else {
						$candidates .= ', ' . $rc['rctitle'];
					}
				}
				if ( !$params ) {
					$this->printLatin1( "$editTime $title ERROR cannot resolve rclog\n" );
					$this->printLatin1( "$editTime $title CANDIDATES: $candidates\n" );
					continue;
				}
			}
			$params['diff'] = $diff;
			$params['title'] = $title;
			$params['diffStartLine'] = $diffStartLine;
			call_user_func( $callback, $params );
		}
		echo "\n";

		if ( !feof( $diffFile ) ) {
			echo "Stopped at line $lineNum\n";
		}
		return true;
	}

	private function reconcileCurrentRevs() {
		foreach ( $this->textCache as $title => $text ) {
			$fileName = "{$this->dataDir}/page/";
			if ( preg_match( '/^[A-Z]/', $title, $m ) ) {
				$fileName .= $m[0];
			} else {
				$fileName .= 'other';
			}
			$fileName .= "/$title.db";

			if ( !file_exists( $fileName ) ) {
				$this->printLatin1( "ERROR: Cannot find page file for {$title}\n" );
				continue;
			}

			$fileContents = file_get_contents( $fileName );
			$page = $this->unserializeUseMod( $fileContents, $this->FS1 );
			$section = $this->unserializeUseMod( $page['text_default'], $this->FS2 );
			$data = $this->unserializeUseMod( $section['data'], $this->FS3 );
			$pageText = $data['text'];
			if ( $text !== $pageText ) {
				$substs = $this->resolveTextChange( $text, $pageText );
				if ( is_array( $substs ) ) {
					foreach ( $substs as $source => $dest ) {
						if ( isset( $this->moveLog[$dest] ) ) {
							$this->printLatin1( "ERROR: need deep rename: $source\n" );
						} else {
							$this->printLatin1( "ERROR: need substitute: $source -> $dest\n" );
						}
					}
				} else {
					$this->printLatin1( "ERROR: unresolved diff in $title:\n" );
					Wikimedia\suppressWarnings();
					$diff = xdiff_string_diff( $text, $pageText ) . '';
					Wikimedia\restoreWarnings();
					$this->printLatin1( "$diff\n" );
				}
			}
		}
	}

	private function makeTitle( $titleText ) {
		return Title::newFromText( $this->encode( $titleText ) );
	}

	private function getText( $titleText ) {
		if ( !isset( $this->textCache[$titleText] ) ) {
			return "Describe the new page here.\n";
		} else {
			return $this->textCache[$titleText];
		}
	}

	private function saveRevision( $params ) {
		$this->textCache[$params['rctitle']] = $params['text'];

		$out = "<page>\n" .
			$this->element( 'title', $params['rctitle'] ) .
			"<revision>\n" .
			$this->element( 'id', $this->revId ++ ) .
			$this->element( 'timestamp', wfTimestamp( TS_ISO_8601, $params['timestamp'] ) ) .
			"<contributor>\n";
		if ( isset( $params['extra']['name'] ) ) {
			$out .= $this->element( 'username', $params['extra']['name'] );
		}
		if ( isset( $params['extra']['id'] ) ) {
			$out .= $this->element( 'id', $params['extra']['id'] );
		}
		if ( isset( $params['host'] ) ) {
			$out .= $this->element( 'ip', $params['host'] );
		}
		$out .=
			"</contributor>\n" .
			$this->element( 'comment', $params['summary'] ) .
			"<text xml:space=\"preserve\">" .
			htmlspecialchars( $this->encode( $params['text'] ) ) .
			"</text>\n" .
			"</revision>\n" .
			"</page>\n";
		fwrite( $this->outFile, $out );
	}

	private function renameTextLinks( $old, $new, $timestamp ) {
		$newWithUnderscores = $new;
		$old = str_replace( '_', ' ', $old );
		$new = str_replace( '_', ' ', $new );

		foreach ( $this->textCache as $title => $oldText ) {
			if ( $newWithUnderscores === $title
				&& in_array( $title, $this->skipSelfSubstitution ) ) {
				// Hack to make Pythagorean_Theorem etc. work
				continue;
			}

			$newText = $this->substituteTextLinks( $old, $new, $oldText );
			if ( $oldText !== $newText ) {
				$this->saveRevision( [
					'rctitle' => $title,
					'timestamp' => $timestamp,
					'text' => $newText,
					'extra' => [ 'name' => 'Page move link fixup script' ],
					'summary' => '',
					'minor' => true
				] );
			}
		}
	}

	private function substituteTextLinks( $old, $new, $text ) {
		$this->saveUrl = [];
		$this->old = $old;
		$this->new = $new;

		$text = str_replace( $this->FS, '', $text ); # Remove separators (paranoia)
		$text = preg_replace_callback( '/(<pre>(.*?)<\/pre>)/is',
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( '/(<code>(.*?)<\/code>)/is',
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( '/(<nowiki>(.*?)<\/nowiki>)/s',
			[ $this, 'storeRaw' ], $text );

		$text = preg_replace_callback( "/\[\[{$this->FreeLinkPattern}\|([^\]]+)\]\]/",
			[ $this, 'subFreeLink' ], $text );
		$text = preg_replace_callback( "/\[\[{$this->FreeLinkPattern}\]\]/",
			[ $this, 'subFreeLink' ], $text );
		$text = preg_replace_callback( "/(\[{$this->UrlPattern}\s+([^\]]+?)\])/",
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( "/(\[{$this->InterLinkPattern}\s+([^\]]+?)\])/",
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( "/(\[?{$this->UrlPattern}\]?)/",
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( "/(\[?{$this->InterLinkPattern}\]?)/",
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( "/{$this->LinkPattern}/",
			[ $this, 'subWikiLink' ], $text );

		$text = preg_replace_callback( "/{$this->FS}(\d+){$this->FS}/",
			[ $this, 'restoreRaw' ], $text );   # Restore saved text
		return $text;
	}

	private function getLinkList( $text ) {
		$this->saveUrl = [];
		$this->linkList = [];

		$text = str_replace( $this->FS, '', $text ); # Remove separators (paranoia)
		$text = preg_replace_callback( '/(<pre>(.*?)<\/pre>)/is',
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( '/(<code>(.*?)<\/code>)/is',
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( '/(<nowiki>(.*?)<\/nowiki>)/s',
			[ $this, 'storeRaw' ], $text );

		$text = preg_replace_callback( "/\[\[{$this->FreeLinkPattern}\|([^\]]+)\]\]/",
			[ $this, 'storeLink' ], $text );
		$text = preg_replace_callback( "/\[\[{$this->FreeLinkPattern}\]\]/",
			[ $this, 'storeLink' ], $text );
		$text = preg_replace_callback( "/(\[{$this->UrlPattern}\s+([^\]]+?)\])/",
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( "/(\[{$this->InterLinkPattern}\s+([^\]]+?)\])/",
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( "/(\[?{$this->UrlPattern}\]?)/",
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( "/(\[?{$this->InterLinkPattern}\]?)/",
			[ $this, 'storeRaw' ], $text );
		$text = preg_replace_callback( "/{$this->LinkPattern}/",
			[ $this, 'storeLink' ], $text );

		return $this->linkList;
	}

	private function storeRaw( $m ) {
		$this->saveUrl[] = $m[1];
		return $this->FS . ( count( $this->saveUrl ) - 1 ) . $this->FS;
	}

	private function subFreeLink( $m ) {
		$link = $m[1];
		if ( isset( $m[2] ) ) {
			$name = $m[2];
		} else {
			$name = '';
		}
		$oldlink = $link;
		$link = preg_replace( '/^\s+/', '', $link );
		$link = preg_replace( '/\s+$/', '', $link );
		if ( $link == $this->old ) {
			$link = $this->new;
		} else {
			$link = $oldlink;  # Preserve spaces if no match
		}
		$link = "[[$link";
		if ( $name !== "" ) {
			$link .= "|$name";
		}
		$link .= "]]";
		return $this->storeRaw( [ 1 => $link ] );
	}

	private function subWikiLink( $m ) {
		$link = $m[1];
		if ( $link == $this->old ) {
			$link = $this->new;
			if ( !preg_match( "/^{$this->LinkPattern}$/", $this->new ) ) {
				$link = "[[$link]]";
			}
		}
		return $this->storeRaw( [ 1 => $link ] );
	}

	private function restoreRaw( $m ) {
		return $this->saveUrl[$m[1]];
	}

	private function storeLink( $m ) {
		$this->linkList[] = $m[1];
		return $this->storeRaw( $m );
	}

	private function encode( $s ) {
		// @phan-suppress-next-line PhanTypeMismatchArgumentInternal
		return strtr( $s, $this->encodeMap );
	}

	private function decode( $s ) {
		return strtr( $s, $this->decodeMap );
	}

	private function printLatin1( $s ) {
		echo $this->encode( $s );
	}

	private function unserializeUseMod( $s, $sep ) {
		$parts = explode( $sep, $s );
		$result = [];
		for ( $i = 0; $i < count( $parts ); $i += 2 ) {
			$result[$parts[$i]] = $parts[$i + 1];
		}
		return $result;
	}
}

$maintClass = ImportUseModWikipedia::class;
require_once RUN_MAINTENANCE_IF_MAIN;
