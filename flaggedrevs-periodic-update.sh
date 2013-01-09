#!/bin/bash

. /srv/deployment/mediawiki/common/multiversion/MWRealm.sh
FILE=`getRealmSpecificFilename /srv/deployment/mediawiki/common/dblists/flaggedrevs.dblist`

for db in `<$FILE`;do
	echo $db
	php -n /srv/deployment/mediawiki/common/multiversion/MWScript.php extensions/FlaggedRevs/maintenance/updateStats.php $db
done
