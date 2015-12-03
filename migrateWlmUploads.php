<?php
/**
 * WLM app 1.2.4 erroneously uploaded to testwiki. This script moves such uploads
 * to Commons preserving authorship information.
 *
 * Wikimedia specific!
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

require_once __DIR__ . '/WikimediaMaintenance.php';

class MigrateWlmUploads extends Maintenance {
	private $dryRun,
		$testerUsernames = array(
			'Jdlrobson',
			'Tfinc',
			'Brion VIBBER',
			'Awjrichards',
			'Philinje',
		),
		$home,
		$logFiles = array( 'found', 'done', 'exists', 'nouser' ),
		$tmpDir,
		$deletingUser;

	/* @var DatabaseBase */
	private $commonsDbw;

	public function __construct() {
		parent::__construct();
		$this->addDescription = 'Migrate botched up uploads from testwiki to Commons';
		$this->addOption( 'do-it', 'Actually perform the migration (otherwise a dry run will be performed)' );
	}

	public function execute() {
		global $wgDBname;
		if ( $wgDBname != 'testwiki' ) {
			$this->error( 'This script must be run on testwiki', 1 );
		}

		wfSuppressWarnings();
		// Make an empty temp directory
		$this->tmpDir = wfTempDir() . '/wlmcleanup';
		unlink( $this->tmpDir );
		mkdir( $this->tmpDir );
		// Delete all log files from previous runs
		foreach ( $this->logFiles as $file ) {
			unlink( $this->fileName( $file ) );
		}
		wfRestoreWarnings();


		$this->home = getenv( 'HOME' );
		$this->dryRun = !$this->hasOption( 'do-it' );

		$dbw = $this->getDB( DB_MASTER );
		$this->commonsDbw = $this->getDB( DB_MASTER, array(), 'commonswiki' );
		$wlmTemplate = Title::newFromText( 'Template:Uploaded with WLM Mobile' );
		$this->deletingUser = User::newFromName( 'Maintenance script' );

		$res = $dbw->select(
			array( 'templatelinks', 'page', 'image' ),
			array( 'page_title', 'img_user_text', 'img_timestamp' ),
			array(
				'tl_namespace' => $wlmTemplate->getNamespace(),
				'tl_title' => $wlmTemplate->getDBkey(),
				'tl_from = page_id',
				'page_namespace' => NS_FILE,
				'page_title = img_name',
			),
			__METHOD__
		);

		foreach ( $res as $row ) {
			$title = $row->page_title;
			$user = $row->img_user_text;
			$timestamp = $row->img_timestamp;

			$this->logToFile( 'found', array( $title, $user ) );
			$this->output( "$title by $user uploaded at $timestamp\n" );
			if ( in_array( $user, $this->testerUsernames ) ) {
				$this->output( "   ...this user is an app tester, rejecting\n" );
				continue;
			}
			$this->migrateFile( $title, $user, $timestamp );
		}

		if ( $this->dryRun ) {
			$this->output( "\nDry run complete\n" );
		}

		wfSuppressWarnings();
		rmdir( $this->tmpDir );
		wfRestoreWarnings();
	}

	private function fileName( $group ) {
		return "{$this->home}/{$group}.log";
	}

	/**
	 * @param string $name: Name of file to log to, relative to user's home directory
	 * @param string|array $what: What to log
	 */
	private function logToFile( $name, $what ) {
		if ( !in_array( $name, $this->logFiles ) ) {
			throw new MWException( "Unexpected logging group '$name'!" );
		}

		$file = fopen( $this->fileName( $name ), 'at' );
		if ( is_array( $what ) ) {
			fputcsv( $file, $what );
		} else {
			fputs( $file, "$what\n" );
		}
		fclose( $file );
	}

	private function migrateFile( $fileName, $user, $timestamp ) {
		if ( !$this->commonsDbw->selectField( 'user', 'user_id', array( 'user_name' => $user ) ) ) {
			$this->logToFile( 'nouser', array( $fileName, $user ) );
			$this->output( "   ...No such user found on destination wiki\n" );
			return;
		}
		$localFile = wfLocalFile( $fileName );
		$remoteFileName = $this->commonsDbw->selectField( 'image', 'img_name', array( 'img_sha1' => $localFile->getSha1() ) );
		if ( $remoteFileName ) {
			$this->logToFile( 'exists', array( $fileName, $user, $remoteFileName ) );
			$this->output( "   ...File already exists on destination wiki as $remoteFileName\n" );
			return;
		}

		$title = Title::makeTitle( NS_FILE, $fileName );
		$rev = Revision::newFromTitle( $title, 0, Revision::READ_LATEST );
		$text = $rev->getText() . "\n{{WLM image from testwiki}}";
		$descFile = "{$this->tmpDir}/desc.txt";

		// Because uploading directly to Commons requires manipulation with globals and other scary stuff,
		// we just call upload script instead
		$tempFile = "{$this->tmpDir}/{$fileName}";
		$cmd = 'sudo -u apache mwscript importImages.php --wiki=commonswiki'
		 	. ' --user=' . wfEscapeShellArg( $user )
			. ' --comment-file=' . wfEscapeShellArg( $descFile )
			. ' --summary=' . wfEscapeShellArg( 'WLM image automatically imported from testwiki' )
			. ' --timestamp=' . wfEscapeShellArg( $timestamp )
			. ' ' . wfEscapeShellArg( $this->tmpDir );
		$this->output( "   ...Executing $cmd\n" );
		if ( $this->dryRun ) {
			$this->logToFile( 'done', array( $fileName, $user ) );
			return;
		}

		file_put_contents( $descFile, $text );
		$localCopy = $localFile->getRepo()->getLocalCopy( $localFile->getVirtualUrl() );
		copy( $localCopy->getPath(), $tempFile );

		$retval = 0;
		$output = wfShellExec( $cmd, $retval, array(), array( 'memory' => 1024*512 ) );
		if ( $output ) {
			$this->output( $output . "\n" );
		}
		if ( $retval ) {
			$this->error( '*** Upload error, aborting ***', 1 );
		}
		$this->logToFile( 'done', array( $fileName, $user ) );

		wfSuppressWarnings();
		unlink( $tempFile );
		wfRestoreWarnings();

/*
		$wp = WikiPage::factory( $title );
		$error = '';
		$reason = 'This erroneous upload has been migrated to [[commons:|Commons]]';
		$localFile->delete( $reason );
		$wp->doDeleteArticle( $reason, false, 0, true, $error, $this->deletingUser );
*/
	}
}

$maintClass = 'MigrateWlmUploads';
require_once( RUN_MAINTENANCE_IF_MAIN );
