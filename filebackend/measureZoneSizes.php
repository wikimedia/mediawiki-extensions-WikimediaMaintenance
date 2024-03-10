<?php
require_once __DIR__ . '/../WikimediaMaintenance.php';

use MediaWiki\MediaWikiServices;

class MeasureZoneSizes extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'backend1', 'file backend 1', true, true );
		$this->addOption( 'backend2', 'file backend 2', true, true );
		$this->addOption( 'fast', 'Estimate size from a sample of shards.' );
		$this->addOption( 'outfile', 'File to report backend size discrepencies.', false, true );
	}

	public function execute() {
		global $wgDBname;

		$b1Name = $this->getOption( 'backend1' );
		$fileBackendGroup = MediaWikiServices::getInstance()->getFileBackendGroup();
		$b1 = $fileBackendGroup->get( $b1Name );
		$b2Name = $this->getOption( 'backend2' );
		$b2 = $fileBackendGroup->get( $b2Name );

		$file = $this->getOption( 'outfile' );
		$fast = $this->hasOption( 'fast' );

		if ( $fast ) {
			$psuffix = dechex( mt_rand( 0, 15 ) );
			$psuffix .= '/' . $psuffix . dechex( mt_rand( 0, 15 ) );
			$dsuffix = dechex( mt_rand( 0, 15 ) ) . '/' . dechex( mt_rand( 0, 15 ) );
			$pfactor = 256;
			$dfactor = 1296;
		} else {
			$psuffix = dechex( mt_rand( 0, 15 ) );
			$dsuffix = dechex( mt_rand( 0, 15 ) );
			$pfactor = 16;
			$dfactor = 36;
		}

		$output = "Sampling from public shard '$psuffix'.\n";
		$output .= "Store\t\tZone\t\tObjects\t\tTotal bytes\t\n";
		// Public zone files (Backend 1)...
		$dirC = $b1->getRootStoragePath() . "/local-public/$psuffix"; // current
		$dirA = $b1->getRootStoragePath() . "/local-public/archive/$psuffix"; // archived
		[ $countC, $bytesC ] = $this->getSizeOfDirectory( $b1, $dirC );
		[ $countA, $bytesA ] = $this->getSizeOfDirectory( $b1, $dirA );
		$b1_count = ( $countC + $countA ) * $pfactor;
		$b1_bytes = ( $bytesC + $bytesA ) * $pfactor;
		$output .= "B1\t\tpublic\t\t$b1_count\t\t$b1_bytes\t\n";
		// Public zone files (Backend 2)...
		$dirC = $b2->getRootStoragePath() . "/local-public/$psuffix"; // current
		$dirA = $b2->getRootStoragePath() . "/local-public/archive/$psuffix"; // archived
		[ $countC, $bytesC ] = $this->getSizeOfDirectory( $b2, $dirC );
		[ $countA, $bytesA ] = $this->getSizeOfDirectory( $b2, $dirA );
		$b2_count = ( $countC + $countA ) * $pfactor;
		$b2_bytes = ( $bytesC + $bytesA ) * $pfactor;
		$output .= "B2\t\tpublic\t\t$b2_count\t\t$b2_bytes\t\n";

		if ( $file && ( $b2_count <= 0.97 * $b1_count || $b2_bytes < 0.97 * $b1_bytes ) ) {
			file_put_contents( $file, "$wgDBname:\n$output", LOCK_EX | FILE_APPEND );
		}
		$this->output( $output );

		$output = "Sampling from deleted shard '$dsuffix'.\n";
		$output .= "Store\t\tZone\t\tObjects\t\tTotal bytes\t\n";
		// Deleted zone files (Backend 1)...
		$dir = $b1->getRootStoragePath() . "/local-deleted/$dsuffix";
		[ $count, $bytes ] = $this->getSizeOfDirectory( $b1, $dir );
		$b1_count = $count * $dfactor;
		$b1_bytes = $bytes * $dfactor;
		$output .= "B1\t\tdeleted\t\t$b1_count\t\t$b1_bytes\t\n";
		// Deleted zone files (Backend 2)...
		$dir = $b2->getRootStoragePath() . "/local-deleted/$dsuffix";
		[ $count, $bytes ] = $this->getSizeOfDirectory( $b2, $dir );
		$b2_count = $count * $dfactor;
		$b2_bytes = $bytes * $dfactor;
		$output .= "B2\t\tdeleted\t\t$b2_count\t\t$b2_bytes\t\n";

		if ( $file && ( $b2_count <= 0.97 * $b1_count || $b2_bytes < 0.97 * $b1_bytes ) ) {
			file_put_contents( $file, "$wgDBname:\n$output", LOCK_EX | FILE_APPEND );
		}
		$this->output( $output );
	}

	protected function getSizeOfDirectory( FileBackend $backend, $dir ) {
		$bytes = 0;
		$count = 0;
		$list = $backend->getFileList( [ 'dir' => $dir, 'adviseStat' => true ] );
		foreach ( $list as $relPath ) {
			$bytes += (int)$backend->getFileSize( [ 'src' => "{$dir}/{$relPath}" ] );
			$count++;
		}
		return [ $count, $bytes ];
	}
}
$maintClass = MeasureZoneSizes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
