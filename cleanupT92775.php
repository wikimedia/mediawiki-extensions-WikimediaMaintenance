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

class CleanupT92775 extends Maintenance {
	public function execute() {
		$ids = [];
		$res = wfGetDB( DB_REPLICA )->select(
			'logging',
			[ 'log_id' ],
			[ 'log_params' => "1 week\nanononly\nnocreate" ],
			__METHOD__
		);
		foreach ( $res as $row ) {
			$ids[] = $row->log_id;
		}

		wfGetDB( DB_MASTER )->update(
			'logging',
			[ 'log_params' => "1 week\nanononly,nocreate" ],
			[ 'log_id' => $ids ],
			__METHOD__
		);
	}
}

$maintClass = CleanupT92775::class;
require_once RUN_MAINTENANCE_IF_MAIN;
