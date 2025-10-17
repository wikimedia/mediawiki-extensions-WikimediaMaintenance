<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/CirrusSearch',
		'../../extensions/cldr',
		'../../extensions/Cognate',
		'../../extensions/Echo',
		'../../extensions/MassMessage',
		'../../extensions/Wikibase',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/CirrusSearch',
		'../../extensions/cldr',
		'../../extensions/Cognate',
		'../../extensions/Echo',
		'../../extensions/MassMessage',
		'../../extensions/Wikibase',
	]
);

$cfg['exclude_file_list'] = array_merge(
	$cfg['exclude_file_list'],
	[
		'../../extensions/MassMessage/.phan/stubs/Event.php',
	]
);

return $cfg;
