<?php

/**
 * Removes htmlCacheUpdate categorylinks jobs caused by the bug fixed in r59718.
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

class FixJobQueueExplosion extends Maintenance {
	public function execute() {
		global $IP;

		$dbw = wfGetDB( DB_MASTER );
		if ( $dbw->tableExists( 'job_explosion_tmp' ) ) {
			echo "Temporary table already exists!\n" .
				"To restart, drop the job table and rename job_explosion_tmp back to job.\n";
			exit( 1 );
		}
		$batchSize = 1000;

		$jobTable = $dbw->tableName( 'job' );
		$jobTmpTable = $dbw->tableName( 'job_explosion_tmp' );
		$dbw->query( "RENAME TABLE $jobTable TO $jobTmpTable" );
		$dbw->sourceFile( "$IP/maintenance/archives/patch-job.sql" );

		$start = 0;
		$numBatchesDone = 0;
		while ( true ) {
			$res = $dbw->select( 'job_explosion_tmp', '*',
				[
					'job_id > ' . $dbw->addQuotes( $start ),
					"NOT ( job_cmd = 'htmlCacheUpdate' AND " .
						"job_params LIKE '%s:13:\"categorylinks\"%' )"
				],
				__METHOD__, [ 'LIMIT' => $batchSize ] );

			if ( !$res->numRows() ) {
				break;
			}

			$insertBatch = [];
			foreach ( $res as $row ) {
				$start = $row->job_id;
				$insertRow = [];
				foreach ( (array)$row as $name => $value ) {
					$insertRow[$name] = $value;
				}
				unset( $insertRow['job_id'] ); // use autoincrement to avoid key conflicts
				$insertBatch[] = $insertRow;
			}
			$dbw->insert( 'job', $insertBatch, __METHOD__ );
			$numBatchesDone++;

			wfWaitForSlaves( 2 );
			if ( $numBatchesDone % 1000 == 0 ) {
				echo "$start\n";
			} elseif ( $numBatchesDone % 10 == 0 ) {
				echo "$start\r";
			}
		}
	}
}

$maintClass = FixJobQueueExplosion::class;
require_once RUN_MAINTENANCE_IF_MAIN;
