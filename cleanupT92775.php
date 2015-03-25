<?php

/**
 * Cleans up CU-created block logs that had wrongly formatted parameters.
 * See https://phabricator.wikimedia.org/T92775
 * Should be equivalent to this query:
 * update logging
 *  set log_params = '1 week\nanononly,nocreate'
 * where log_type = 'block'
 *  and log_action = 'block'
 *  and log_params = '1 week\nanononly\nnocreate';
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

class FixCUBlockLogs extends WikimediaMaintenance {
	public function execute() {
		$ids = array();
		$res = wfGetDB( DB_SLAVE )->select(
			'logging',
			array( 'log_id' ),
			array( 'log_params' => "1 week\nanononly\nnocreate" ),
			__METHOD__
		);
		foreach ( $res as $row ) {
			$ids[] = $row->log_id;
		}

		wfGetDB( DB_MASTER )->update(
			'logging',
			array( 'log_params' => "1 week\nanononly,nocreate" ),
			array( 'log_id' => $ids ),
			__METHOD__
		);
	}
}

$maintClass = 'FixCUBlockLogs';
require_once DO_MAINTENANCE_IF_MAIN;
