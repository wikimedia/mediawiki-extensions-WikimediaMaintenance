<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		// all files from the root and the folders
		'.',
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/CirrusSearch',
		'../../extensions/cldr',
		'../../extensions/Cognate',
		'../../extensions/MassMessage',
		'../../extensions/Wikibase/lib',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		// all is coming from the vendor in mediawiki/core
		'vendor',
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/CirrusSearch',
		'../../extensions/cldr',
		'../../extensions/Cognate',
		'../../extensions/MassMessage',
		'../../extensions/Wikibase/lib',
	]
);

return $cfg;
