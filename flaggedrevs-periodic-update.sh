#!/bin/bash

. /home/wikipedia/common/multiversion/MWRealm.sh
FILE=`getRealmSpecificFilename /usr/local/apache/common/flaggedrevs.dblist`

for db in `<$FILE`;do
	echo $db
	php -n /home/wikipedia/common/multiversion/MWScript.php extensions/FlaggedRevs/maintenance/updateStats.php $db
done
