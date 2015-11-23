<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * Invalidate MessageBlobStore cache keys
 */
class RefreshMessageBlobs extends Maintenance {
	public function execute() {
		MessageBlobStore::clear();
		wfWaitForSlaves();
	}
}

$maintClass = 'RefreshMessageBlobs';
require_once DO_MAINTENANCE;
