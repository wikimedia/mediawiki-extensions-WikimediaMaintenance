<?php

require_once __DIR__ . '/WikimediaMaintenance.php';

class PasswordAudit extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addArg( "users", "List of usernames to check." );
		$this->addArg( "passwordlist", "List of passwords to check." );

		$this->addOption( 'centralauth', 'Use CentralAuth for Password DB', false, false );
	}

	public function execute() {
		$file_users = $this->getArg( 0 );
		$file_passwords = $this->getArg( 1 );
		$userList = file( $file_users );
		$passList = file( $file_passwords );
		$this->output(
			"Testing " . count( $userList ) .
			" users against " . count( $passList ) .
			" passwords...\n"
		);
		$cnt = 0;
		$hcnt = 0;
		$startts = microtime( true );
		$this->output( "Starting: " . date( 'Ymd-H:i:s', $startts ) . "...\n" );

		$ca = $this->getOption( 'centralauth', false );

		if ( $ca ) {
			$dbr = CentralAuthUtils::getCentralSlaveDB();
		} else {
			$dbr = wfGetDB( DB_REPLICA );
		}

		foreach ( $userList as $user ) {
			$cnt++;
			$username = trim( $user );

			$this->output( "Working on '$username'..." );

			$passwordFactory = new PasswordFactory();
			$passwordFactory->init( RequestContext::getMain()->getConfig() );

			if ( $ca ) {
				$hash = $dbr->selectField(
					'globaluser',
					'gu_password',
					[ 'gu_name' => $username ],
					__METHOD__
				);

			} else {
				$hash = $dbr->selectField(
					'user',
					'user_password',
					[ 'user_name' => $username ],
					__METHOD__
				);
			}

			$pbkdf2 = false;

			if ( substr( $hash, 0, 8 ) === ':pbkdf2:' ) {
				$pbkdf2 = true;
			}

			$mPassword = $passwordFactory->newFromCiphertext( $hash );

			foreach ( $passList as $password ) {
				$hcnt++;
				$password = trim( $password );

				$match = $pbkdf2 ? $this->fastPbkdf2test( $hash, $password ) : $mPassword->verify( $password );
				if ( $match ) {
					$this->output( "*** MATCH: $username / $password\n" );
					break;
				}
				$this->output( '.' );
			}

			$this->output(
				"Finished '$username', averaging: " .
				( microtime( true ) - $startts ) / $cnt .
				" seconds/account, " . ( microtime( true ) - $startts ) / $hcnt .
				" sec/hash\n"
			);
		}

		$this->output( "Ended: " . ( microtime( true ) - $startts ) . " seconds.\n" );
	}

	/**
	 * @param string $hash
	 * @param string $test
	 * @return bool
	 */
	public function fastPbkdf2test( $hash, $test ) {
		$pieces = explode( ':', $hash );
		$salt = base64_decode( $pieces[5] );
		$roundTotal = $lastRound = hash_hmac( 'sha256', $salt . pack( 'N', 1 ), $test, true );
		for ( $j = 1; $j < (int)$pieces[3]; ++$j ) {
			$lastRound = hash_hmac( 'sha256', $lastRound, $test, true );
			$roundTotal ^= $lastRound;
		}
		return ( $roundTotal === substr( base64_decode( $pieces[6] ), 0, 32 ) );
	}

}

$maintClass = 'PasswordAudit';
require_once RUN_MAINTENANCE_IF_MAIN;
