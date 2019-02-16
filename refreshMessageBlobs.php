<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\MediaWikiServices;

/**
 * Invalidate MessageBlobStore cache keys
 */
class RefreshMessageBlobs extends Maintenance {
	public function execute() {
		$blobStore = new MessageBlobStore( MediaWikiServices::getInstance()->getResourceLoader() );
		$blobStore->clear();
	}
}

$maintClass = 'RefreshMessageBlobs';
require_once RUN_MAINTENANCE_IF_MAIN;
