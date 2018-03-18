<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

class PurgeUrls extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Send purge requests to Varnish for listed urls' );
		$this->addOption( 'cluster', 'The Varnish cluster to be contacted (default: cache_text).', false, true );
		$this->addOption( 'verbose', 'Show more output', false, false, 'v' );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		global $wgSquidServers, $wgHTCPRouting;

		// Ensure direct purging is disabled
		$wgSquidServers = [];

		// Select cluster
		$cluster = $this->getOption( 'cluster', 'cache_text' );
		switch ( $cluster ) {
		case 'cache_text':
			// Use value from wmf-config (239.128.0.112, 4827)
			break;
		case 'cache_misc':
			// FIXME: Hardcoded https://github.com/wikimedia/operations-puppet/commit/71e9017
			$wgHTCPRouting = [ '' => [ 'host' => '239.128.0.115', 'port' => 4827 ] ];
			break;
		default:
			$this->error( 'Invalid --cluster value.' );
			return;
		}

		$urls = [];
		$stdin = $this->getStdin();
		while ( !feof( $stdin ) ) {
			$url = trim( fgets( $stdin ) );
			if ( preg_match( '%^https?://%', $url ) ) {
				$urls[] = $url;
			} elseif ( $url !== '' ) {
				$this->output( "Invalid url (skipped): $url\n" );
			}
		}
		$this->output( "Purging " . count( $urls ) . " urls\n" );
		$this->doPurge( $urls );
		$this->output( "Done!\n" );
	}

	private function doPurge( array $urls ) {
		$chunks = array_chunk( $urls, $this->mBatchSize );
		foreach ( $chunks as $chunk ) {
			if ( $this->hasOption( 'verbose' ) ) {
				$this->output( implode( "\n", $urls ) . "\n" );
			}
			// Use ::purge() instead of doUpdate() to bypass jobqueue rebound purge
			CdnCacheUpdate::purge( $chunk );
		}
	}
}

$maintClass = 'PurgeUrls';
require_once RUN_MAINTENANCE_IF_MAIN;
