#!/bin/bash

dir=`dirname $0`
test -e $dir/orphans || mkdir -p $dir/orphans
for db in `</usr/local/apache/common/all.dblist`;do
	echo $db
	mysql -h `mwscript getSlaveServer.php --wiki=$db` -N -B $db < $dir/find-orphans.sql > $dir/orphans/$db
done
