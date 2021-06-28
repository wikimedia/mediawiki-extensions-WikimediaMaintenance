<?php

require_once __DIR__ . '/../WikimediaCommandLine.inc';

$lines = file( $args[0] );
if ( !$lines ) {
	echo "Unable to open file {$args[0]}\n";
	exit( 1 );
}

$lines = array_map( 'trim', $lines );
$opsByWiki = [];

foreach ( $lines as $line ) {
	if ( !preg_match( '/
		^
		(\w+): \s*
		renaming \s
		(\d+) \s
		\((\d+),\'(.*?)\'\)
		/x',
		$line, $m ) ) {
		continue;
	}

	list( $whole, $wiki, $pageId, $ns, $dbk ) = $m;

	$opsByWiki[$wiki][] = [
		'id' => $pageId,
		'ns' => $ns,
		'dbk' => $dbk ];
}

$lbFactory = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
foreach ( $opsByWiki as $wiki => $ops ) {
	$lb = $lbFactory->getMainLB( $wiki );
	$db = $lb->getConnection( DB_PRIMARY, [], $wiki );

	foreach ( $ops as $op ) {
		$msg = "{$op['id']} -> {$op['ns']}:{$op['dbk']}";

		# Sanity check
		$row = $db->selectRow( 'page', [ 'page_namespace', 'page_title' ],
			[ 'page_id' => $op['id'] ], 'revertCleanupTitles.php' );
		if ( !$row ) {
			echo "$wiki: missing: $msg\n";
			continue;
		}

		if ( !preg_match( '/^Broken\//', $row->page_title ) ) {
			echo "$wiki: conflict: $msg\n";
			continue;
		}

		$db->update( 'page',
			/* SET */ [
				'page_namespace' => $op['ns'],
				'page_title' => $op['dbk'] ],
			/* WHERE */ [
				'page_id' => $op['id'] ],
			'revertCleanupTitles.php' );
		echo "$wiki: updated: $msg\n";
	}
	$lb->reuseConnection( $db );
}
