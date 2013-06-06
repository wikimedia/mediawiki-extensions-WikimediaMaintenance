#!/bin/bash

. /a/common/multiversion/MWRealm.sh
FILE=`getRealmSpecificFilename /a/common/flaggedrevs.dblist`

for db in `<$FILE`;do
	echo $db
	php -n /a/common/multiversion/MWScript.php extensions/FlaggedRevs/maintenance/updateStats.php $db
done
