<?php
/**
 * @defgroup Wikimedia Wikimedia
 */

/**
 * Recover evicted files from bug 39615.
 *
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
 * @ingroup Wikimedia
 */
require_once( __DIR__ . '/WikimediaMaintenance.php' );

class FixBug39615 extends WikimediaMaintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'move', "Actually move the files", false, false );
		$this->addOption( 'logdir', "File to log to", true, true );
		$this->mDescription = "Fix files that were affected by bug 39615 and still broken";
		$this->setBatchSize( 100 );
	}

	public function execute() {
		global $wgDBname;

		$name = ''; // page on img_name
		$repo = RepoGroup::singleton()->getLocalRepo();

		$logFile = $this->getOption( 'logdir' ) . "/$wgDBname";
		if ( !file_put_contents( $logFile, "STARTED " . wfTimestamp() . "\n", FILE_APPEND ) ) {
			$this->error( "Could not write to log file.", 1 ); // die
		}

		$count = 0;
		$dbr = wfGetDB( DB_SLAVE );
		$cutoff = $dbr->addQuotes( $dbr->timestamp( time() - 86400*60 ) ); // 2 months
		while ( true ) {
			$this->output( "Doing next batch from '$name'.\n" );
			$res = $dbr->select( 'image', '*',
				array( "img_name > {$dbr->addQuotes( $name )}" ),
				__METHOD__,
				array( 'ORDER BY' => 'img_name ASC', 'LIMIT' => $this->mBatchSize )
			);
			if ( !$res->numRows() ) {
				break; // done
			}
			foreach ( $res as $row ) {
				++$count;
				$name = $row->img_name;
				$file = LocalFile::newFromRow( $row, $repo );
				$file->lock();
				if ( !$repo->fileExists( $file->getPath() ) ) { // 404
					$this->output( "Current version missing for '$name'.\n" );
					$jpath = $dbr->selectField( 'filejournal',
						'fj_path',
						array( 'fj_new_sha1' => $file->getSha1(), "fj_timestamp > {$cutoff}" ),
						__METHOD__,
						array( 'ORDER BY' => 'fj_id DESC' )
					);
					if ( $jpath === false || strpos( $jpath, "!" ) === false ) {
						$this->output( "No logs for '$jpath'.\n" );
						continue; // no entry or not evicted to archive name ("<timestamp>!<name>")
					}
					$path = preg_replace( # fj_path is under the "master" backend
						'!^mwstore://[^/]+!/', $repo->getBackend()->getRootStoragePath(), $jpath
					);
					if ( $repo->getFileSha1( $path ) === $file->getSha1() ) {
						if ( !file_put_contents( $logFile, "$path => {$file->getPath()}\n", FILE_APPEND ) ) {
							$this->error( "Could not write to log file.", 1 ); // die
						}
						# File was evicted to an archive name
						if ( $this->hasOption( 'move' ) ) {
							$status = $this->repo()->getBackend()->move( array(
								'src' => $path, 'dst' => $file->getPath()
							) );
							if ( !$status->isOK() ) {
								print_r( $status->getErrorsArray() );
							} else {
								$this->output( "Moved $path to {$file->getPath()}.\n" );
							}
						} else {
							$this->output( "Should move $path to {$file->getPath()}.\n" );
						}
					}
				}
				$file->unlock();
			}
		}
		$this->output( "Done. [$count rows].\n" );
	}
}

$maintClass = "FixBug39615";
require_once( RUN_MAINTENANCE_IF_MAIN );
