<?php

use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\SecurePoll\MailingListEntry;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../..';
}

require_once "$IP/maintenance/Maintenance.php";

// This file is not autoloaded
// @phan-file-suppress PhanUndeclaredClassMethod,PhanUndeclaredClassProperty
require_once "$IP/extensions/SecurePoll/cli/wm-scripts/includes/MailingListEntry.php";

/**
 * Make a mailing list for T300265
 */
class MakeUserPrefixMailingList extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Make a mailing list to email all users with a ' .
			'specified username prefix. Mailing can then be done by SecurePoll\'s sendMail.php.' );
		$this->addOption( 'prefix', 'The username prefix', true, true );
		$this->addOption( 'activedays', 'Select users active within this many days',
			false, true );
		$this->addOption( 'format', 'May be SecurePoll (default) or MassMessage',
			false, true );
	}

	public function execute() {
		global $wgConf;

		$prefix = $this->getOption( 'prefix' );
		$format = strtolower( $this->getOption( 'format', 'securepoll' ) );
		$activeDays = $this->getOption( 'activedays' );

		$dbc = CentralAuthServices::getDatabaseManager()->getCentralDB( DB_REPLICA );
		$lbf = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$queryBuilder = $dbc->newSelectQueryBuilder()
			->select( [ 'gu_name', 'gu_home_db', 'gu_email', 'global_lang' => 'gp_value' ] )
			->from( 'globaluser' )
			->leftJoin( 'global_preferences', null, [
				'gp_user=gu_id',
				'gp_property' => 'language'
			] )
			->where( 'gu_name' . $dbc->buildLike( $prefix, $dbc->anyString() ) )
			->caller( __METHOD__ );

		if ( $format === 'securepoll' ) {
			$queryBuilder->andWhere( 'gu_email <> ' . $dbc->addQuotes( '' ) )
				->andWhere( 'gu_email_authenticated IS NOT NULL' );
		}
		$res = $queryBuilder->fetchResultSet();
		foreach ( $res as $row ) {
			if ( $activeDays ) {
				$wiki = $this->getActiveWiki( $row->gu_name, (int)$activeDays );
			} else {
				$wiki = $row->gu_home_db;
			}
			if ( !$wiki ) {
				continue;
			}

			[ $site, $siteLang ] = $wgConf->siteFromDB( $wiki );

			if ( $row->global_lang === null ) {
				// 4000 out of 4006 users hit this case
				$dbr = $lbf->getReplicaDatabase( $wiki );
				$localLang = $dbr->newSelectQueryBuilder()
					->select( 'up_value' )
					->from( 'user' )
					->join( 'user_properties', null, [ 'up_user=user_id' ] )
					->where( [
						'up_property' => 'language',
						'user_name' => $row->gu_name
					] )
					->caller( __METHOD__ )
					->fetchField();
				$lang = $localLang ?: $siteLang;
			} else {
				$lang = $row->global_lang;
			}

			$siteName = $wgConf->get( 'wgSitename', $wiki, $site );
			$server = str_replace(
				'$lang',
				$siteLang,
				$wgConf->get( 'wgCanonicalServer', $wiki, $site )
			);
			$domain = str_replace( 'https://', '', $server );

			switch ( $format ) {
				case 'securepoll':
					$entry = new MailingListEntry;
					$entry->wiki = $wiki;
					$entry->siteName = $siteName;
					$entry->userName = $row->gu_name;
					$entry->email = $row->gu_email;
					$entry->language = $lang;
					$entry->editCount = 0;
					print $entry->toString();
					break;

				case 'massmessage':
					print "User:{$row->gu_name}@$domain\n";
			}
		}
	}

	/**
	 * Get the wiki where the user has been most recently active, or null if the
	 * user was active more than $activeDays ago.
	 *
	 * @param string $userName
	 * @param int $activeDays
	 * @return string|null
	 */
	private function getActiveWiki( $userName, $activeDays ) {
		$databaseManager = CentralAuthServices::getDatabaseManager();
		$dbc = $databaseManager->getCentralDB( DB_REPLICA );
		$wikis = $dbc->newSelectQueryBuilder()
			->select( 'lu_wiki' )
			->from( 'localuser' )
			->where( [ 'lu_name' => $userName ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		$activeWiki = null;
		foreach ( $wikis as $wiki ) {
			if ( !WikiMap::getWiki( $wiki ) ) {
				continue;
			}
			if ( $wiki === 'loginwiki' ) {
				continue;
			}
			$db = $databaseManager->getLocalDB( DB_REPLICA, $wiki );

			$ts = $db->newSelectQueryBuilder()
				->select( 'MAX(rev_timestamp)' )
				->from( 'revision' )
				->join( 'actor', null, 'rev_actor=actor_id' )
				->where( [ 'actor_name' => $userName ] )
				->fetchField();

			if ( $ts !== null ) {
				$days = ( time() - (int)wfTimestamp( TS_UNIX, $ts ) ) / 86400;
				if ( $days < $activeDays ) {
					$activeDays = $days;
					$activeWiki = $wiki;
				}
			}
		}
		return $activeWiki;
	}
}

$maintClass = MakeUserPrefixMailingList::class;
require RUN_MAINTENANCE_IF_MAIN;
