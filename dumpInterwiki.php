<?php
/**
 * Build constant slightly compact database of interwiki prefixes
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
 * @todo document
 * @ingroup Maintenance
 * @ingroup Wikimedia
 */
require_once __DIR__ . '/WikimediaMaintenance.php';
require_once __DIR__ . '/WMFSite.php';

use Cdb\Exception as CdbException;
use Cdb\Writer as CdbWriter;
use MediaWiki\MediaWikiServices;

class DumpInterwiki extends Maintenance {

	/**
	 * @var array|null
	 */
	protected $langlist;
	/**
	 * @var array|null
	 */
	protected $dblist;
	/**
	 * @var array|null
	 */
	protected $specials;
	/**
	 * @var array|null
	 */
	protected $prefixLists;

	/**
	 * @var CdbWriter|false
	 */
	protected $dbFile = false;

	/** @var string */
	protected $urlprotocol;

	/**
	 * @var string
	 */
	protected $end = '.org';

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
		global $wmgRealm;

		return [
			[ 'm', $this->urlprotocol . '//meta.wikimedia' . $this->end . '/wiki/$1', 1 ],
			[ 'meta', $this->urlprotocol . '//meta.wikimedia' . $this->end . '/wiki/$1', 1 ],
			[ 'sep11', $this->urlprotocol . '//sep11.wikipedia.org/wiki/$1', 1 ],
			[
				'd',
				$this->urlprotocol . ( $wmgRealm === 'labs' ? '//wikidata' : '//www.wikidata' )
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

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Build constant slightly compact database of interwiki prefixes." );
		$this->addOption( 'langlist', 'File with one language code per line', false, true );
		$this->addOption( 'dblist', 'File with one db per line', false, true );
		$this->addOption( 'specialdbs', "File with one 'special' db per line", false, true );
		$this->addOption( 'o', 'Cdb output file', false, true );
		$this->addOption( 'insecure', 'Output wikimedia interwiki urls using HTTP instead of HTTPS',
			false, false );

		global $wmgRealm;
		if ( $wmgRealm === 'labs' ) {
			$this->end = '.beta.wmflabs.org';
		}
	}

	private function removeComments( $array ) {
		return array_filter( $array, static function ( $element ) {
			return strpos( $element, '#' ) !== 0;
		} );
	}

	public function execute() {
		global $wmgRealm;
		$root = getenv( 'MEDIAWIKI_DEPLOYMENT_DIR' ) ?: '/srv/mediawiki';

		if ( !file_exists( "$root/dblists" ) ) {
			throw new Exception( "Can't run script: MEDIAWIKI_DEPLOYMENT_DIR environment variable"
				. " must be set to MediaWiki root directory." );
		}

		$dblistSpecial = "$root/dblists/special.dblist";
		$dblistAll = $wmgRealm === 'labs' ? "$root/dblists/all-labs.dblist" : "$root/dblists/all.dblist";
		$langlist = $wmgRealm === 'labs' ? "$root/langlist-labs" : "$root/langlist";

		// List of language prefixes likely to be found in multi-language sites
		$this->langlist = array_map( "trim", file( $this->getOption(
			'langlist',
			$langlist
		) ) );

		// List of all database names
		$this->dblist = $this->removeComments(
			array_map( "trim", file( $this->getOption( 'dblist', $dblistAll ) ) )
		);

		// Special-case databases
		$this->specials = $this->removeComments( array_flip(
			array_map( "trim", file( $this->getOption( 'specialdbs', $dblistSpecial ) ) )
		) );

		if ( $this->hasOption( 'o' ) ) {
			try {
				$this->dbFile = CdbWriter::open( $this->getOption( 'o' ) );
			} catch ( CdbException $e ) {
				$this->fatalError( "Unable to open cdb file for writing" );
			}
		} else {
			$this->output( "<?php\n" );
			$this->output( '// Automatically generated by dumpInterwiki.php on ' .
				date( DATE_RFC2822 ) . "\n" );
			$this->output( "return [\n" );
		}

		if ( $this->hasOption( 'insecure' ) ) {
			$this->urlprotocol = 'http:';
		} else {
			$this->urlprotocol = 'https:';
		}

		$this->getRebuildInterwikiDump();
	}

	private function getRebuildInterwikiDump() {
		global $wmgRealm;

		$sites = $this->getSites();
		$extraLinks = $this->getExtraLinks();

		// Construct a list of reserved prefixes
		$reserved = [];
		foreach ( $this->langlist as $lang ) {
			$reserved[$lang] = 1;
		}
		if ( $wmgRealm === 'production' ) {
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
		$intermap = Http::get( $url, [ 'timeout' => 30 ], __METHOD__ );
		$lines = array_map( 'trim', explode( "\n", trim( $intermap ) ) );

		if ( !$lines || count( $lines ) < 2 ) {
			$this->fatalError( "m:Interwiki_map not found" );
		}

		// Global interwiki map
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\|\s*(.*?)\s*\|\|\s*(.*?)\s*$/', $line, $matches ) ) {
				$prefix = $contLang->lc( $matches[1] );
				$prefix = str_replace( ' ', '_', $prefix );

				$url = $matches[2];
				if ( preg_match(
					'/(?:\/\/|\.)(wikipedia|wiktionary|wikisource|wikiquote|wikibooks|wikimedia|' .
						'wikinews|wikiversity|wikivoyage|wikimediafoundation|mediawiki|wikidata)\.org/',
					$url )
				) {
					$local = 1;
				} else {
					$local = 0;
				}

				if ( empty( $reserved[$prefix] ) ) {
					$imap = [ "iw_prefix" => $prefix, "iw_url" => $url, "iw_local" => $local ];
					$this->makeLink( $imap, "__global" );
				}
			}
		}

		// Exclude Wikipedia for Wikipedia
		$this->makeLink( [ 'iw_prefix' => 'wikipedia', 'iw_url' => null ], "_wiki" );

		// Multilanguage sites
		foreach ( $sites as $site ) {
			$this->makeLanguageLinks( $site, "_" . $site->suffix );
		}

		foreach ( $this->dblist as $db ) {
			if ( isset( $this->specials[$db] ) && !isset( self::$siteOverrides[$db] ) ) {
				// Special wiki
				// Has interwiki links and interlanguage links to wikipedia

				$this->makeLink( [ 'iw_prefix' => $db, 'iw_url' => "wiki" ], "__sites" );
				// Links to multilanguage sites
				/**
				 * @var $targetSite WMFSite
				 */
				foreach ( $sites as $targetSite ) {
					$this->makeLink( [ 'iw_prefix' => $targetSite->prefix,
						'iw_url' => $targetSite->getURL( 'en', $this->urlprotocol ),
						'iw_local' => 1 ], $db );
				}
			} else {
				// Find out which site this DB belongs to
				$site = false;
				if ( isset( self::$siteOverrides[$db] ) ) {
					/**
					 * @var string $site
					 */
					list( $site, $lang ) = self::$siteOverrides[$db];
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

				$this->makeLink( [ 'iw_prefix' => $db, 'iw_url' => $site->suffix ], "__sites" );
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

					$this->makeLink( [ 'iw_prefix' => $targetSite->prefix,
						'iw_url' => $targetSite->getURL( $lateralLang, $this->urlprotocol ),
						'iw_local' => 1 ], $db );
				}

			}
		}
		foreach ( $extraLinks as $link ) {
			$this->makeLink( $link, "__global" );
		}

		// List prefixes for each source
		foreach ( $this->prefixLists as $source => $hash ) {
			$list = array_keys( $hash );
			if ( $this->dbFile ) {
				try {
					$this->dbFile->set( "__list:{$source}", implode( ' ', $list ) );
				} catch ( CdbException $e ) {
					throw new MWException( $e->getMessage() );
				}
			} else {
				$k = "__list:{$source}";
				$v = implode( ' ', $list );
				$this->output( "\t'$k' => '$v',\n" );
			}
		}
		if ( !$this->dbFile ) {
			$this->output( "];\n" );
		}
	}

	/**
	 * Executes part of an INSERT statement,
	 * corresponding to all interlanguage links to a particular site
	 *
	 * @param WMFSite &$site
	 * @param string $source
	 */
	private function makeLanguageLinks( &$site, $source ) {
		global $wmgRealm;

		// Actual languages with their own databases
		foreach ( $this->langlist as $targetLang ) {
			$this->makeLink(
				[ $targetLang, $site->getURL( $targetLang, $this->urlprotocol ), 1 ],
				$source
			);
		}

		// Language aliases
		if ( $wmgRealm === 'production' ) {
			foreach ( self::$languageAliases as $alias => $lang ) {
				// Very special edge case: T214400
				if ( $site->suffix === 'wiktionary' && $alias === 'yue' ) {
					$this->makeLink(
						[ $lang, $site->getURL( $alias, $this->urlprotocol ), 1 ],
						$source
					);
				} else {
					$this->makeLink(
						[ $alias, $site->getURL( $lang, $this->urlprotocol ), 1 ],
						$source
					);
				}
			}
		}

		// Additional links
		$additionalLinks = $this->getAdditionalLinks( $site->suffix );
		foreach ( $additionalLinks as $link ) {
			$this->makeLink( $link, $source );
		}
	}

	/**
	 * @param array $entry
	 * @param string $source
	 * @throws MWException
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
		if ( $this->dbFile ) {
			try {
				$this->dbFile->set( "{$source}:{$entry['iw_prefix']}",
					trim( "{$entry['iw_local']} {$entry['iw_url']}" )
				);
			} catch ( CdbException $e ) {
				throw new MWException( $e->getMessage() );
			}
		} else {
			$k = "{$source}:{$entry['iw_prefix']}";
			$v = trim( "{$entry['iw_local']} {$entry['iw_url']}" );
			$this->output( "\t'$k' => '$v',\n" );
		}
		// Add to list of prefixes
		$this->prefixLists[$source][$entry['iw_prefix']] = 1;
	}
}

$maintClass = DumpInterwiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
