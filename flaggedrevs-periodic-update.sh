#!/bin/bash

FILE="/srv/mediawiki/dblists/flaggedrevs.dblist"

for db in `<$FILE`;do
	echo $db
	php -n /srv/mediawiki/multiversion/MWScript.php extensions/FlaggedRevs/maintenance/updateStats.php $db
done
