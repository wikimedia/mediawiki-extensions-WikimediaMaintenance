<?php

/**
 * Refresh the msg_resource table when cdb message files have been updated
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

class RefreshMessageBlobs extends Maintenance {
	function execute() {
		global $IP;

		# Get modification timesatmp for English (fallback) from the l10n cache
		$enModTime = filemtime( "$IP/cache/l10n/l10n_cache-en.cdb" );
		$langModTime = array( 'en' => $enModTime );

		# To avoid cache stampede, fetch all the non-empty resource message
		# blobs and update them one at a time manually. To avoid excess memory
		# usage in LocalisationCache, order by language and clear the cache
		# between each language.
		$dbr = wfGetDB( DB_SLAVE );
		$dbw = wfGetDB( DB_MASTER );
		$res = $dbr->select( 'msg_resource',
			array( 'mr_resource', 'mr_lang', 'mr_blob', 'mr_timestamp' ),
			"mr_blob != '{}'",
			__METHOD__,
			array( 'ORDER BY' => 'mr_lang' )
		);
		$prevLang = false;
		$rows = 0;
		foreach ( $res as $row ) {
			# Check modification time for this language
			if ( !isset( $langModTime[$row->mr_lang] ) ) {
				$file = "$IP/cache/l10n/l10n_cache-$row->mr_lang.cdb";
				if ( file_exists( $file ) ) {
					$langModTime[$row->mr_lang] = filemtime( $file );
				} else {
					$langModTime[$row->mr_lang] = $enModTime;
				}
			}
			if ( wfTimestamp( TS_UNIX, $row->mr_timestamp ) >= $langModTime[$row->mr_lang] ) {
				continue;
			}

			# Clear LocalisationCache of the old language to reduce memory usage
			if ( $prevLang !== false && $prevLang !== $row->mr_lang ) {
				Language::getLocalisationCache()->unload( $prevLang );
			}
			$prevLang = $row->mr_lang;

			# Update message blob. Even though we read from a slave and are
			# writing to master, it should be safe because we're including
			# mr_timestamp in the WHERE clause.
			$messages = FormatJson::decode( $row->mr_blob, true );
			foreach ( $messages as $key => $value ) {
				$messages[$key] = wfMessage( $key )->inLanguage( $row->mr_lang )->plain();
			}
			$dbw->update( 'msg_resource',
				array(
					'mr_blob' => FormatJson::encode( (object)$messages ),
					'mr_timestamp' => $dbw->timestamp(),
				),
				array(
					'mr_resource' => $row->mr_resource,
					'mr_lang' => $row->mr_lang,
					'mr_timestamp' => $row->mr_timestamp,
				),
				__METHOD__
			);
			if ( ++$rows % 1000 == 0 ) {
				wfWaitForSlaves();
			}
		}
		wfWaitForSlaves();
	}
}

$maintClass = 'RefreshMessageBlobs';
require_once( DO_MAINTENANCE );
