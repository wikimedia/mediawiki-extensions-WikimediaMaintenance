<?php

namespace MediaWiki\Extension\WikimediaMaintenance;

use MediaWiki\Shell\Shell;

require_once __DIR__ . '/WikimediaMaintenance.php';

class MessageHistory extends \Maintenance {
	private $dir;
	private $name;
	private $outputFileName;
	private $relPath;
	private $startTime;
	private $messageValues;
	private $currentCommit;
	private $currentTimestamp;
	private $startingCommit;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'dir',
			'The git repo base directory', true, true );
		$this->addOption( 'name',
			'The message name', true, true );
		$this->addOption( 'out',
			'The output file name', true, true );
		$this->addOption( 'i18n',
			'The relative path to the i18n directory', false, true );
		$this->addOption( 'start',
			'The timestamp at which to start the search', false, true );
	}

	public function execute() {
		$this->dir = $this->getOption( 'dir' );
		$this->name = $this->getOption( 'name' );
		$this->outputFileName = $this->getOption( 'out' );
		$this->relPath = $this->getOption( 'i18n', 'i18n' );
		$this->startTime = $this->getOption( 'start', '1990-01-01' );

		$this->chdir( $this->dir );

		$this->saveCommit();
		$this->getMessageValues();
		while ( $this->goBack() ) {
			$this->getMessageValues();
		}
		$this->restoreCommit();
		$this->writeHistory();
	}

	private function saveCommit() {
		$this->currentCommit = $this->startingCommit = $this->git( 'rev-parse', 'HEAD' );
		$this->currentTimestamp = $this->git( 'log', '-1', '--format=tformat:\'%ci\'' );
	}

	private function getMessageValues() {
		foreach ( glob( "{$this->relPath}/*.json" ) as $fileName ) {
			$slashPos = strrpos( $fileName, '/' );
			$dotPos = strrpos( $fileName, '.' );
			$lang = substr( $fileName, $slashPos + 1, $dotPos - $slashPos - 1 );
			if ( !strlen( $lang ) ) {
				$this->fatalError( "Unable to determine language for file {$fileName}" );
			}

			$messages = json_decode( file_get_contents( $fileName ), true );
			if ( !$messages ) {
				$this->error( "Unable to read file {$fileName}" );
				continue;
			}
			if ( isset( $messages[$this->name] ) ) {
				$value = $messages[$this->name];
				if ( !isset( $this->messageValues[$lang][$value] ) ) {
					$this->messageValues[$lang][$value] = [
						'last' => $this->currentTimestamp,
					];
				}
				$this->messageValues[$lang][$value]['first'] = $this->currentTimestamp;
			}
		}
	}

	private function goBack() {
		$ret = $this->git( 'checkout', 'HEAD^' );
		if ( $ret === false ) {
			return false;
		}
		$this->currentCommit = $this->git( 'rev-parse', 'HEAD' );
		$this->currentTimestamp = $this->git( 'log', '-1', '--format=tformat:%ci' );
		if ( strtotime( $this->currentTimestamp ) < strtotime( $this->startTime ) ) {
			return false;
		}
		return true;
	}

	private function chdir( $path ) {
		if ( !chdir( $path ) ) {
			$this->fatalError( "Unable to change directory to \"$path\"" );
		}
	}

	private function restoreCommit() {
		$this->git( 'checkout', $this->startingCommit );
	}

	private function writeHistory() {
		$data = [];
		foreach ( $this->messageValues as $lang => $values ) {
			foreach ( $values as $value => $info ) {
				$data[$lang][] = [
					'value' => $value,
					'start' => $info['first'],
					'end' => $info['last']
				];
			}
		}
		file_put_contents( $this->outputFileName,
			json_encode( $data,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
		);
	}

	private function git( ...$args ) {
		array_unshift( $args, 'git' );
		$result = Shell::command( $args )
			->execute();
		if ( $result->getExitCode() === 0 ) {
			return trim( $result->getStdout() );
		} else {
			return false;
		}
	}
}

$maintClass = MessageHistory::class;
require_once RUN_MAINTENANCE_IF_MAIN;
