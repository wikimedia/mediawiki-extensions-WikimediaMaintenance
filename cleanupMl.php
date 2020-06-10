<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

require_once __DIR__ . '/WikimediaCommandLine.inc';

$file = fopen( $args[0], 'r' );
if ( !$file ) {
	exit( 1 );
}

$user = User::newSystemUser( 'Malayalam cleanup script', [ 'steal' => true ] );
$wgUser = $user;

$dbw = wfGetDB( DB_MASTER );

while ( !feof( $file ) ) {
	$line = fgets( $file );
	if ( $line === false ) {
		echo "Read error\n";
		exit( 1 );
	}
	$line = trim( $line );
	// Remove BOM
	$line = str_replace( "\xef\xbb\xbf", '', $line );

	if ( $line === '' ) {
		continue;
	}
	if ( !preg_match( '/^\[\[(.*)]]$/', $line, $m ) ) {
		echo "Invalid line: $line\n";
		print bin2hex( $line ) . "\n";
		continue;
	}
	$brokenTitle = Title::newFromText( $m[1] );
	if ( !preg_match( '/^Broken\//', $brokenTitle->getDBkey() ) ) {
		echo "Unbroken title: $line\n";
		continue;
	}

	$unbrokenTitle = Title::makeTitleSafe(
		$brokenTitle->getNamespace(),
		preg_replace( '/^Broken\//', '', $brokenTitle->getDBkey() ) );

	# Check that the broken title is a redirect
	$revision = MediaWikiServices::getInstance()
		->getRevisionLookup()
		->getRevisionByTitle( $brokenTitle );
	if ( !$revision ) {
		echo "Does not exist: $line\n";
		continue;
	}
	$content = $revision->getContent( SlotRecord::MAIN );
	$text = ContentHandler::getContentText( $content );
	if ( $text === false ) {
		echo "Cannot load text: $line\n";
		continue;
	}
	$redir = ContentHandler::makeContent( $text, null, CONTENT_MODEL_WIKITEXT )->getRedirectTarget();
	if ( !$redir ) {
		echo "Not a redirect: $line\n";
		continue;
	}

	if ( $unbrokenTitle->exists() ) {
		# Exists already, just delete this redirect
		$status = WikiPage::factory( $brokenTitle )
			->doDeleteArticleReal( 'Redundant redirect', $user );
		if ( $status->isOk() ) {
			echo "Deleted: $line\n";
		} else {
			echo "Failed to delete: $line\n";
		}
	} else {
		# Does not exist, move this redirect to the unbroken title
		# Do not leave a redirect behind
		$result = MediaWikiServices::getInstance()
			->getMovePageFactory()
			->newMovePage( $brokenTitle, $unbrokenTitle )
			->move(
				$user,
				/*reason*/ 'Fixing broken redirect',
				/*createRedirect*/ false
			);
		if ( $result->isOK() ) {
			echo "Moved: $line\n";
		} else {
			echo "Move error at $line: $result\n";
		}
	}

	$dbw->commit( 'cleanupMI' );
	sleep( 1 );
	$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
	$lbFactory->waitForReplication( [ 'ifWritesSince' => 5 ] );
}
