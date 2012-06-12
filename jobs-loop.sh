#!/bin/bash
#
# NAME
# jobs-loop.sh -- Continuously process a MediaWiki jobqueue
#
# SYNOPSIS
# jobs-loop.sh [job_type]
#
# DESCRIPTION
# jobs-loop.sh is an infinite "while loop" used to call MediaWiki runJobs.php
# and eventually attempt to process any job enqueued. MediaWiki jobs are split
# into several types, by default jobs-loop.sh will:
#  - first attempt to run the internally priorized jobs (see `types` variable
#    in script).
#  - proceed any default jobs
#
# MediaWiki configuration variable $wgJobTypesExcludedFromDefaultQueue is used
# to exclude some types from the default processing. Those excluded job types
# could be processed on dedicated boxes by running jobs-loop.sh using the
# job_type parameter.
#
# You will probably want to run this script under your webserver username.
#
# Example:
# // Process job queues:
# jobs-loop.sh
#
# // Process jobs of type `webVideoTranscode`
# jobs-loop.sh webVideoTranscode
#

# Limit virtual memory
ulimit -v 400000

# When killed, make sure we are also getting ride of the child jobs
# we have spawned.
trap 'kill %-; exit' SIGTERM
[ ! -z "$1" ] && {
	echo "starting type-specific job runner: $1"
	type=$1
}

#types="htmlCacheUpdate sendMail enotifNotify uploadFromUrl fixDoubleRedirect renameUser"
types="sendMail enotifNotify uploadFromUrl fixDoubleRedirect MoodBarHTMLMailerJob ArticleFeedbackv5MailerJob RenderJob"

cd `readlink -f /usr/local/apache/common/multiversion`
while [ 1 ];do

	# Do the prioritised types
	moreprio=y
	while [ -n "$moreprio" ] ; do
		moreprio=
		for type in $types; do
			db=`php -n MWScript.php nextJobDB.php --wiki=aawiki --type="$type"`
			if [ -n "$db" ]; then
				echo "$db $type"
				nice -n 20 php MWScript.php runJobs.php --wiki="$db" --procs=5 --type="$type" --maxtime=300 &
				wait
				moreprio=y
			fi
		done
	done

	# Do the remaining types
	db=`php -n MWScript.php nextJobDB.php --wiki=aawiki`

	if [ -z "$db" ];then
		# No jobs to do, wait for a while
		echo "No jobs..."
		sleep 5
	else
		echo "$db"
		nice -n 20 php MWScript.php runJobs.php --wiki="$db" --procs=5 --maxtime=300 &
		wait
	fi
done
