<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

use MediaWiki\MediaWikiServices;

/**
 * Invalidate MessageBlobStore cache keys
 */
class RefreshMessageBlobs extends Maintenance {
	public function execute() {
		$blobStore = MediaWikiServices::getInstance()->getResourceLoader()->getMessageBlobStore();
		$blobStore->clear();
	}
}

$maintClass = RefreshMessageBlobs::class;
require_once RUN_MAINTENANCE_IF_MAIN;
