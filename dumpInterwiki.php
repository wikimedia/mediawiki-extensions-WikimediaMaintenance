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
 */
require_once __DIR__ . '/WikimediaMaintenance.php';
require_once __DIR__ . '/WMFSite.php';

use MediaWiki\MediaWikiServices;
use Wikimedia\StaticArrayWriter;

/**
 * Build an $wgInterwikiCache array based on the [[m:Interwiki_map]] page on Meta-Wiki.
 *
 * @ingroup Maintenance
 * @ingroup Wikimedia
 */
class DumpInterwiki extends Maintenance {
	protected ?array $langlist;
	protected ?array $dblist;
	protected ?array $specials;
	protected string $realm;
	protected string $mwconfigDir;
	protected ?array $prefixLists;
	protected string $urlprotocol;
	protected string $end;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Build constant slightly compact database of interwiki prefixes." );
		$this->addOption( 'mwconfig-dir',
			'Path to operations/mediawiki-config checkout. '
				. 'Used for langlist, all.dblist and special.dblist',
			false, true );
		$this->addOption( 'target-realm', 'One of "labs" (beta.wmflabs.org) or "production".',
			false, true );
		$this->addOption( 'insecure', 'Output wikimedia interwiki urls using HTTP instead of HTTPS',
			false, false );
	}

	/**
	 * Returns an array of multi-language sites
	 * db suffix => db suffix, iw prefix, hostname
	 * @return array
	 */
	protected function getSites() {
		return [
			'wiki' => new WMFSite( 'wiki', 'w', 'wikipedia' . $this->end ),
			'wiktionary' => new WMFSite( 'wiktionary', 'wikt', 'wiktionary' . $this->end ),
			'wikiquote' => new WMFSite( 'wikiquote', 'q', 'wikiquote' . $this->end ),
			'wikibooks' => new WMFSite( 'wikibooks', 'b', 'wikibooks' . $this->end ),
			'wikinews' => new WMFSite( 'wikinews', 'n', 'wikinews' . $this->end ),
			'wikisource' => new WMFSite( 'wikisource', 's', 'wikisource' . $this->end ),
			'wikimedia' => new WMFSite( 'wikimedia', 'chapter', 'wikimedia' . $this->end ),
			'wikiversity' => new WMFSite( 'wikiversity', 'v', 'wikiversity' . $this->end ),
			'wikivoyage' => new WMFSite( 'wikivoyage', 'voy', 'wikivoyage' . $this->end ),
		];
	}

	/**
	 * Returns an array of extra global interwiki links that can't be in the
	 * intermap for some reason
	 * @return array
	 */
	protected function getExtraLinks() {
		return [
			[ 'c', $this->urlprotocol . '//commons.wikimedia' . $this->end . '/wiki/$1', 1 ],
			[ 'm', $this->urlprotocol . '//meta.wikimedia' . $this->end . '/wiki/$1', 1 ],
			[ 'meta', $this->urlprotocol . '//meta.wikimedia' . $this->end . '/wiki/$1', 1 ],
			[
				'd',
				$this->urlprotocol . ( $this->realm === 'labs' ? '//wikidata' : '//www.wikidata' )
					. $this->end . '/wiki/$1',
				1
			],
			[
				'f',
				$this->urlprotocol . ( $this->realm === 'labs' ? '//wikifunctions' : '//www.wikifunctions' )
					. $this->end . '/wiki/$1',
				1
			],
		];
	}

	/**
	 * Site overrides for wikis whose DB names end in 'wiki' but that really belong
	 * to another site
	 * @var array
	 */
	protected static $siteOverrides = [
		'sourceswiki' => [ 'wikisource', 'en' ],
	];

	/**
	 * Language aliases, usually configured as redirects to the real wiki in apache
	 * Interlanguage links are made directly to the real wiki
	 * @var array
	 */
	protected static $languageAliases = [
		# Nasty legacy codes
		'cz' => 'cs',
		'be-x-old' => 'be-tarask',
		'dk' => 'da',
		'epo' => 'eo',
		'jp' => 'ja',
		'zh-cn' => 'zh',
		'zh-tw' => 'zh',
		# Real ISO language codes to our fake ones
		'cmn' => 'zh',
		'egl' => 'eml',
		'en-simple' => 'simple', # T283149
		'gsw' => 'als',
		'lzh' => 'zh-classical',
		'nan' => 'zh-min-nan',
		'nb' => 'no',
		'rup' => 'roa-rup',
		'sgs' => 'bat-smg',
		'vro' => 'fiu-vro',
		'yue' => 'zh-yue',
	];

	/**
	 * Special case prefix rewrites, for the benefit of Swedish which uses s:t
	 * as an abbreviation for saint
	 * @var array
	 */
	protected static $prefixRewrites = [
		'svwiki' => [ 's' => 'src' ],
	];

	/**
	 * Set the wiki's interproject links to point to some other language code
	 * Useful for chapter wikis (e.g. brwikimedia (Brazil chapter) has nothing to
	 *   do with br language (Breton), interwikis like w: should point to
	 *   Portuguese projects instead)
	 * @var array
	 */
	protected static $languageOverrides = [
		'wikimedia' => [
			'ar' => 'es',
			'bd' => 'bn',
			'be' => 'en',
			'br' => 'pt',
			'ca' => 'en',
			'cn' => 'zh',
			'co' => 'es',
			'dk' => 'da',
			'il' => 'he',
			'mx' => 'es',
			'noboard_chapters' => 'no',
			'nyc' => 'en',
			'nz' => 'en',
			'pa_us' => 'en',
			'rs' => 'sr',
			'se' => 'sv',
			'ua' => 'uk',
			'uk' => 'en',
		],
		'wikiversity' => [
			'beta' => 'en',
		],
	];

	/**
	 * Additional links to provide for the needs of the different projects
	 * @param string $project The site (e.g. wikibooks)
	 * @return array
	 */
	protected function getAdditionalLinks( $project ) {
		switch ( $project ) {
			case 'wikibooks':
				return [
					[ 'b', $this->urlprotocol . '//en.wikibooks' . $this->end . '/wiki/$1', 1 ],
				];
			case 'wikinews':
				return [
					[ 'n', $this->urlprotocol . '//en.wikinews' . $this->end . '/wiki/$1', 1 ],
				];
			case 'wikiquote':
				return [
					[ 'q', $this->urlprotocol . '//en.wikiquote' . $this->end . '/wiki/$1', 1 ],
				];
			case 'wikisource':
				return [
					[ 'mul', $this->urlprotocol . '//wikisource.org/wiki/$1', 1 ],
					[ 's', $this->urlprotocol . '//en.wikisource' . $this->end . '/wiki/$1', 1 ],
				];
			case 'wikiversity':
				return [
					[ 'mul', $this->urlprotocol . '//beta.wikiversity.org/wiki/$1', 1 ],
					[ 'v', $this->urlprotocol . '//en.wikiversity' . $this->end . '/wiki/$1', 1 ],
				];
			case 'wikivoyage':
				return [
					[ 'voy', $this->urlprotocol . '//en.wikivoyage.org/wiki/$1', 1 ],
				];
			case 'wiktionary':
				return [
					[ 'wikt', $this->urlprotocol . '//en.wiktionary' . $this->end . '/wiki/$1', 1 ],
				];
			case 'wiki':
				return [
					[ 'w', $this->urlprotocol . '//en.wikipedia' . $this->end . '/wiki/$1', 1 ],
				];
			default:
				return [];
		}
	}

	private function removeComments( $array ) {
		return array_filter( $array, static function ( $element ) {
			return strpos( $element, '#' ) !== 0;
		} );
	}

	public function execute() {
		// Set --mwconfig-dir and --target-realm automatically in Beta Cluster
		// to ease debugging and for workflow backward compatibility.
		global $wmgRealm;
		$this->realm = $this->getOption( 'target-realm', $wmgRealm === 'labs' ? 'labs' : 'production' );
		$this->mwconfigDir = $this->getOption( 'mwconfig-dir',
			getenv( 'MEDIAWIKI_DEPLOYMENT_DIR' ) ?: '/srv/mediawiki' );

		$this->end = $this->realm === 'labs' ? '.beta.wmflabs.org' : '.org';

		if ( !file_exists( "$this->mwconfigDir/dblists" ) ) {
			$this->fatalError( "--mwconfig-dir is unset or invalid. "
				. "Unable to find dblists directory in $this->mwconfigDir" );
		}

		$dblistSpecial = "{$this->mwconfigDir}/dblists/special.dblist";
		$dblistAll = $this->realm === 'labs'
			? "{$this->mwconfigDir}/dblists/all-labs.dblist"
			: "{$this->mwconfigDir}/dblists/all.dblist";
		$langlist = $this->realm === 'labs'
			? "{$this->mwconfigDir}/langlist-labs"
			: "{$this->mwconfigDir}/langlist";

		// List of language prefixes likely to be found in multi-language sites
		$this->langlist = array_map( "trim", file( $langlist ) );

		// List of all database names
		$this->dblist = $this->removeComments( array_map( "trim", file( $dblistAll ) ) );

		// Special-case databases
		$this->specials = $this->removeComments( array_flip( array_map( "trim", file( $dblistSpecial ) ) ) );

		// TODO: Remove this option.
		if ( $this->hasOption( 'insecure' ) ) {
			$this->urlprotocol = 'http:';
		} else {
			$this->urlprotocol = 'https:';
		}

		$this->getRebuildInterwikiDump();
	}

	private function getRebuildInterwikiDump() {
		$sites = $this->getSites();
		$extraLinks = $this->getExtraLinks();

		// Construct a list of reserved prefixes
		$reserved = [];
		foreach ( $this->langlist as $lang ) {
			$reserved[$lang] = 1;
		}
		if ( $this->realm === 'production' ) {
			foreach ( self::$languageAliases as $alias => $lang ) {
				$reserved[$alias] = 1;
			}
		}

		/**
		 * @var WMFSite $site
		 */
		foreach ( $sites as $site ) {
			$reserved[$site->prefix] = 1;
		}

		// Extract the intermap from meta
		$url = 'https://meta.wikimedia.org/w/index.php?title=Interwiki_map&action=raw';
		$intermap = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->get( $url, [ 'timeout' => 30 ], __METHOD__ );
		$lines = array_map( 'trim', explode( "\n", trim( $intermap ) ) );

		if ( !$lines || count( $lines ) < 2 ) {
			$this->fatalError( "m:Interwiki_map not found" );
		}

		$links = [];

		// Global interwiki map
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\|\s*(.*?)\s*\|\|\s*(.*?)\s*$/', $line, $matches ) ) {
				$prefix = $contLang->lc( $matches[1] );
				$prefix = str_replace( ' ', '_', $prefix );

				$url = $matches[2];
				if ( preg_match(
					'/(?:\/\/|\.)(wikipedia|wiktionary|wikisource|wikiquote|wikibooks|wikimedia|' .
						'wikinews|wikiversity|wikivoyage|wikimediafoundation|mediawiki|wikidata|wikifunctions)\.org/',
					$url )
				) {
					$local = 1;
				} else {
					$local = 0;
				}

				if ( empty( $reserved[$prefix] ) ) {
					$imap = [ "iw_prefix" => $prefix, "iw_url" => $url, "iw_local" => $local ];
					$links += $this->makeLink( $imap, "__global" );
				}
			}
		}

		// Multilanguage sites
		foreach ( $sites as $site ) {
			$links = array_merge( $links, $this->makeLanguageLinks( $site, "_" . $site->suffix ) );
		}

		foreach ( $this->dblist as $db ) {
			if ( isset( $this->specials[$db] ) && !isset( self::$siteOverrides[$db] ) ) {
				// Special wiki - has interwiki links and interlanguage links to wikipedia

				$links = array_merge(
					$links,
					$this->makeLink( [ 'iw_prefix' => $db, 'iw_url' => "wiki" ], "__sites" )
				);
				// Links to multilanguage sites
				/**
				 * @var $targetSite WMFSite
				 */
				foreach ( $sites as $targetSite ) {
					$links = array_merge( $links, $this->makeLink(
						[
							'iw_prefix' => $targetSite->prefix,
							'iw_url' => $targetSite->getURL( 'en', $this->urlprotocol ),
							'iw_local' => 1
						],
						$db
					) );
				}
			} else {
				// Find out which site this DB belongs to
				$site = false;
				if ( isset( self::$siteOverrides[$db] ) ) {
					/**
					 * @var string $site
					 */
					[ $site, $lang ] = self::$siteOverrides[$db];
					$site = $sites[$site];
				} else {
					$matches = [];
					foreach ( $sites as $candidateSite ) {
						$suffix = $candidateSite->suffix;
						if ( preg_match( "/(.*)$suffix$/", $db, $matches ) ) {
							$site = $candidateSite;
							break;
						}
					}
					$lang = $matches[1];
				}

				$links = array_merge(
					$links,
					$this->makeLink( [ 'iw_prefix' => $db, 'iw_url' => $site->suffix ], "__sites" )
				);
				if ( !$site ) {
					$this->error( "Invalid database $db\n" );
					continue;
				}

				// Lateral links
				foreach ( $sites as $targetSite ) {
					// Suppress link to self; these are defined in getAdditionalLinks()
					// and always point to the English-language version of the project
					if ( $targetSite->suffix == $site->suffix ) {
						continue;
					}

					$lateralLang = $lang;
					// Check for language overrides
					if ( isset( self::$languageOverrides[$site->suffix] ) &&
						isset( self::$languageOverrides[$site->suffix][$lang] ) ) {
						$lateralLang = self::$languageOverrides[$site->suffix][$lang];
					}

					$links = array_merge( $links, $this->makeLink(
						[
							'iw_prefix' => $targetSite->prefix,
							'iw_url' => $targetSite->getURL( $lateralLang, $this->urlprotocol ),
							'iw_local' => 1
						],
						$db
					) );
				}

			}
		}
		foreach ( $extraLinks as $link ) {
			$links = array_merge( $links, $this->makeLink( $link, "__global" ) );
		}

		// List prefixes for each source
		foreach ( $this->prefixLists as $source => $hash ) {
			$list = array_keys( $hash );
			$k = "__list:{$source}";
			$v = implode( ' ', $list );
			$links[$k] = $v;
		}

		$this->output(
			StaticArrayWriter::write(
				$links,
				'Automatically generated by dumpInterwiki.php on ' . date( DATE_RFC2822 )
			)
		);
	}

	/**
	 * Executes part of an INSERT statement,
	 * corresponding to all interlanguage links to a particular site
	 *
	 * @param WMFSite &$site
	 * @param string $source
	 * @return array
	 */
	private function makeLanguageLinks( &$site, $source ) {
		$links = [];
		// Actual languages with their own databases
		foreach ( $this->langlist as $targetLang ) {
			$links = array_merge( $links, $this->makeLink(
				[ $targetLang, $site->getURL( $targetLang, $this->urlprotocol ), 1 ],
				$source
			) );
		}

		// Language aliases
		if ( $this->realm === 'production' ) {
			foreach ( self::$languageAliases as $alias => $lang ) {
				// Very special edge case: T214400
				if ( $site->suffix === 'wiktionary' && $alias === 'yue' ) {
					$links = array_merge( $links, $this->makeLink(
						[ $lang, $site->getURL( $alias, $this->urlprotocol ), 1 ],
						$source
					) );
				} else {
					$links = array_merge( $links, $this->makeLink(
						[ $alias, $site->getURL( $lang, $this->urlprotocol ), 1 ],
						$source
					) );
				}
			}
		}

		// Additional links
		$additionalLinks = $this->getAdditionalLinks( $site->suffix );
		foreach ( $additionalLinks as $link ) {
			$links = array_merge( $links, $this->makeLink( $link, $source ) );
		}
		return $links;
	}

	/**
	 * @param array $entry
	 * @param string $source
	 * @return array
	 */
	private function makeLink( $entry, $source ) {
		if ( isset( self::$prefixRewrites[$source] )
			&& isset( $entry[0] )
			&& isset( self::$prefixRewrites[$source][$entry[0]] )
		) {
			$entry[0] = self::$prefixRewrites[$source][$entry[0]];
		}

		if ( !array_key_exists( "iw_prefix", $entry ) ) {
			$entry = [ "iw_prefix" => $entry[0], "iw_url" => $entry[1], "iw_local" => $entry[2] ];
		}
		if ( array_key_exists( $source, self::$prefixRewrites ) &&
				array_key_exists( $entry['iw_prefix'], self::$prefixRewrites[$source] ) ) {
			$entry['iw_prefix'] = self::$prefixRewrites[$source][$entry['iw_prefix']];
		}
		if ( !array_key_exists( "iw_local", $entry ) ) {
			$entry["iw_local"] = null;
		}
		if ( !array_key_exists( "iw_url", $entry ) ) {
			$entry["iw_url"] = '';
		}

		$k = "{$source}:{$entry['iw_prefix']}";
		$v = trim( "{$entry['iw_local']} {$entry['iw_url']}" );

		// Add to the list of prefixes
		$this->prefixLists[$source][$entry['iw_prefix']] = 1;
		return [ $k => $v ];
	}
}

$maintClass = DumpInterwiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
