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

use Cdb\Writer as CdbWriter;
use Cdb\Exception as CdbException;

class DumpInterwiki extends Maintenance {

	/**
	 * @var array
	 */
	protected $langlist, $dblist, $specials, $prefixLists;

	/**
	 * @var CdbWriter|bool
	 */
	protected $dbFile = false;

	protected $urlprotocol;

	/**
	 * @var string
	 */
	protected $end = '.org';

	/**
	 * Returns an array of multi-language sites
	 * db suffix => db suffix, iw prefix, hostname
	 * @returns array
	 */
	protected function getSites() {
		return array(
			'wiki' => new WMFSite( 'wiki', 'w', 'wikipedia' . $this->end ),
			'wiktionary' => new WMFSite( 'wiktionary', 'wikt', 'wiktionary' . $this->end ),
			'wikiquote' => new WMFSite( 'wikiquote', 'q', 'wikiquote' . $this->end ),
			'wikibooks' => new WMFSite( 'wikibooks', 'b', 'wikibooks' . $this->end ),
			'wikinews' => new WMFSite( 'wikinews', 'n', 'wikinews' . $this->end ),
			'wikisource' => new WMFSite( 'wikisource', 's', 'wikisource' . $this->end ),
			'wikimedia' => new WMFSite( 'wikimedia', 'chapter', 'wikimedia' . $this->end ),
			'wikiversity' => new WMFSite( 'wikiversity', 'v', 'wikiversity' . $this->end ),
			'wikivoyage' => new WMFSite( 'wikivoyage', 'voy', 'wikivoyage' . $this->end ),
		);
	}

	/**
	 * Returns an array of extra global interwiki links that can't be in the
	 * intermap for some reason
	 * @returns array
	 */
	protected function getExtraLinks() {
		return array(
			array( 'm', $this->urlprotocol . '//meta.wikimedia' . $this->end . '/wiki/$1', 1 ),
			array( 'meta', $this->urlprotocol . '//meta.wikimedia' . $this->end . '/wiki/$1', 1 ),
			array( 'sep11', $this->urlprotocol . '//sep11.wikipedia.org/wiki/$1', 1 ),
			array( 'd', $this->urlprotocol . '//www.wikidata' . $this->end . '/wiki/$1', 1 ),
		);
	}

	/**
	 * Site overrides for wikis whose DB names end in 'wiki' but that really belong
	 * to another site
	 * @var array
	 */
	protected static $siteOverrides = array(
		'sourceswiki' => array( 'wikisource', 'en' ),
	);

	/**
	 * Language aliases, usually configured as redirects to the real wiki in apache
	 * Interlanguage links are made directly to the real wiki
	 * @var array
	 */
	protected static $languageAliases = array(
		# Nasty legacy codes
		'cz' => 'cs',
		'dk' => 'da',
		'epo' => 'eo',
		'jp' => 'ja',
		'minnan' => 'zh-min-nan',
		'nb' => 'no',
		'zh-cfr' => 'zh-min-nan',
		'zh-cn' => 'zh',
		'zh-tw' => 'zh',
		# Real ISO language codes to our fake ones
		'nan' => 'zh-min-nan',
		'vro' => 'fiu-vro',
		'cmn' => 'zh',
		'lzh' => 'zh-classical',
		'yue' => 'zh-yue',
		'rup' => 'roa-rup',
		'gsw' => 'als',
		'be-tarask' => 'be-x-old',
		'sgs' => 'bat-smg',
		'egl' => 'eml',
	);

	/**
	 * Special case prefix rewrites, for the benefit of Swedish which uses s:t
	 * as an abbreviation for saint
	 */
	protected static $prefixRewrites = array(
		'svwiki' => array( 's' => 'src' ),
	);

	/**
	 * Set the wiki's interproject links to point to some other language code
	 * Useful for chapter wikis (e.g. brwikimedia (Brazil chapter) has nothing to
	 *   do with br language (Breton), interwikis like w: should point to
	 *   Portuguese projects instead)
	 * @var array
	 */
	protected static $languageOverrides = array(
		'wikimedia' => array(
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
		),
		'wikiversity' => array(
			'beta' => 'en',
		),
	);

	/**
	 * Additional links to provide for the needs of the different projects
	 * @param string $project The site (e.g. wikibooks)
	 * @returns array
	 */
	protected function getAdditionalLinks( $project ) {
		switch( $project ) {
			case 'wikibooks':
				return array(
					array( 'b', $this->urlprotocol . '//en.wikibooks' . $this->end . '/wiki/$1', 1 ),
				);
			case 'wikinews':
				return array(
					array( 'n', $this->urlprotocol . '//en.wikinews' . $this->end . '/wiki/$1', 1 ),
				);
			case 'wikiquote':
				return array(
					array( 'q', $this->urlprotocol . '//en.wikiquote' . $this->end . '/wiki/$1', 1 ),
				);
			case 'wikisource':
				return array(
					array( 'mul', $this->urlprotocol . '//wikisource.org/wiki/$1', 1 ),
					array( 's', $this->urlprotocol . '//en.wikisource' . $this->end . '/wiki/$1', 1 ),
				);
			case 'wikiversity':
				return array(
					array( 'mul', $this->urlprotocol . '//beta.wikiversity.org/wiki/$1', 1 ),
					array( 'v', $this->urlprotocol . '//en.wikiversity' . $this->end . '/wiki/$1', 1 ),
				);
			case 'wikivoyage':
				return array(
					array( 'voy', $this->urlprotocol . '//en.wikivoyage.org/wiki/$1', 1 ),
				);
			case 'wiktionary':
				return array(
					array( 'wikt', $this->urlprotocol . '//en.wiktionary' . $this->end . '/wiki/$1', 1 ),
				);
			case 'wiki':
				return array(
					array( 'w', $this->urlprotocol . '//en.wikipedia' . $this->end . '/wiki/$1', 1 ),
				);
			default:
				return array();
		}
	}

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Build constant slightly compact database of interwiki prefixes.";
		$this->addOption( 'langlist', 'File with one language code per line', false, true );
		$this->addOption( 'dblist', 'File with one db per line', false, true );
		$this->addOption( 'specialdbs', "File with one 'special' db per line", false, true );
		$this->addOption( 'o', 'Cdb output file', false, true );
		$this->addOption( 'insecure', 'Output wikimedia interwiki urls using HTTP instead of HTTPS', false, false );

		global $wmfRealm;
		if ( $wmfRealm === 'labs' ) {
			$this->end = '.beta.wmflabs.org';
		}
	}

	function execute() {
		$root = getenv( 'MEDIAWIKI_DEPLOYMENT_DIR' ) ?: '/srv/mediawiki';

		if ( !file_exists( "$root/dblists" ) ) {
			throw new Exception( "Can't run script: MEDIAWIKI_DEPLOYMENT_DIR environment variable must be set to MediaWiki root directory." );
		}

		$default_all_dblist = getRealmSpecificFilename( "$root/dblists/all.dblist" );
		$default_special_dblist = getRealmSpecificFilename( "$root/dblists/special.dblist" );

		// List of language prefixes likely to be found in multi-language sites
		$this->langlist = array_map( "trim", file( $this->getOption(
			'langlist',
			getRealmSpecificFilename( "$root/langlist" )
		) ) );

		// List of all database names
		$this->dblist = array_map( "trim", file( $this->getOption( 'dblist', $default_all_dblist ) ) );

		// Special-case databases
		$this->specials = array_flip( array_map( "trim", file( $this->getOption( 'specialdbs', $default_special_dblist ) ) ) );

		if ( $this->hasOption( 'o' ) ) {
			try {
				$this->dbFile = CdbWriter::open( $this->getOption( 'o' ) ) ;
			} catch ( CdbException $e ) {
				$this->error( "Unable to open cdb file for writing", 1 );
			}
		} else {
			$this->output( "<?php\n" );
			$this->output( '// Automatically generated by dumpInterwiki.php on ' . date( DATE_RFC2822 ) . "\n" );
			$this->output( "return [\n" );
		}

		if ( $this->hasOption( 'insecure' ) ) {
			$this->urlprotocol = 'http:';
		} else {
			$this->urlprotocol = 'https:';
		}

		$this->getRebuildInterwikiDump();
	}

	function getRebuildInterwikiDump() {
		global $wgContLang, $wmfRealm;

		$sites = $this->getSites();
		$extraLinks = $this->getExtraLinks();

		// Construct a list of reserved prefixes
		$reserved = array();
		foreach ( $this->langlist as $lang ) {
			$reserved[$lang] = 1;
		}
		if ( $wmfRealm === 'production' ) {
			foreach ( self::$languageAliases as $alias => $lang ) {
				$reserved[$alias] = 1;
			}
		}

		/**
		 * @var $site WMFSite
		 */
		foreach ( $sites as $site ) {
			$reserved[$site->prefix] = 1;
		}

		// Extract the intermap from meta
		$intermap = Http::get( 'https://meta.wikimedia.org/w/index.php?title=Interwiki_map&action=raw', 30 );
		$lines = array_map( 'trim', explode( "\n", trim( $intermap ) ) );

		if ( !$lines || count( $lines ) < 2 ) {
			$this->error( "m:Interwiki_map not found", true );
		}

		// Global interwiki map
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\|\s*(.*?)\s*\|\|\s*(.*?)\s*$/', $line, $matches ) ) {
				$prefix = $wgContLang->lc( $matches[1] );
				$prefix = str_replace( ' ', '_', $prefix );

				$url = $matches[2];
				if ( preg_match( '/(?:\/\/|\.)(wikipedia|wiktionary|wikisource|wikiquote|wikibooks|wikimedia|' .
					'wikinews|wikiversity|wikivoyage|wikimediafoundation|mediawiki|wikidata)\.org/', $url )
				) {
					$local = 1;
				} else {
					$local = 0;
				}

				if ( empty( $reserved[$prefix] ) ) {
					$imap  = array( "iw_prefix" => $prefix, "iw_url" => $url, "iw_local" => $local );
					$this->makeLink( $imap, "__global" );
				}
			}
		}

		// Exclude Wikipedia for Wikipedia
		$this->makeLink( array( 'iw_prefix' => 'wikipedia', 'iw_url' => null ), "_wiki" );

		// Multilanguage sites
		foreach ( $sites as $site ) {
			$this->makeLanguageLinks( $site, "_" . $site->suffix );
		}

		foreach ( $this->dblist as $db ) {
			if ( isset( $this->specials[$db] ) && !isset( self::$siteOverrides[$db] ) ) {
				// Special wiki
				// Has interwiki links and interlanguage links to wikipedia

				$this->makeLink( array( 'iw_prefix' => $db, 'iw_url' => "wiki" ), "__sites" );
				// Links to multilanguage sites
				/**
				 * @var $targetSite WMFSite
				 */
				foreach ( $sites as $targetSite ) {
					$this->makeLink( array( 'iw_prefix' => $targetSite->prefix,
						'iw_url' => $targetSite->getURL( 'en', $this->urlprotocol ),
						'iw_local' => 1 ), $db );
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
					$matches = array();
					foreach ( $sites as $candidateSite ) {
						$suffix = $candidateSite->suffix;
						if ( preg_match( "/(.*)$suffix$/", $db, $matches ) ) {
							$site = $candidateSite;
							break;
						}
					}
					$lang = $matches[1];
				}

				$this->makeLink( array( 'iw_prefix' => $db, 'iw_url' => $site->suffix ), "__sites" );
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

					$this->makeLink( array( 'iw_prefix' => $targetSite->prefix,
						'iw_url' => $targetSite->getURL( $lateralLang, $this->urlprotocol ),
						'iw_local' => 1 ), $db );
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
	 * Executes part of an INSERT statement, corresponding to all interlanguage links to a particular site
	 *
	 * @param $site WMFSite
	 * @param $source
	 */
	function makeLanguageLinks( &$site, $source ) {
		global $wmfRealm;

		// Actual languages with their own databases
		foreach ( $this->langlist as $targetLang ) {
			$this->makeLink( array( $targetLang, $site->getURL( $targetLang, $this->urlprotocol ), 1 ), $source );
		}

		// Language aliases
		if ( $wmfRealm === 'production' ) {
			foreach ( self::$languageAliases as $alias => $lang ) {
				$this->makeLink( array( $alias, $site->getURL( $lang, $this->urlprotocol ), 1 ), $source );
			}
		}

		// Additional links
		$additionalLinks = $this->getAdditionalLinks( $site->suffix );
		foreach ( $additionalLinks as $link ) {
			$this->makeLink( $link, $source );
		}
	}

	/**
	 * @param $entry
	 * @param $source
	 * @throws MWException
	 */
	function makeLink( $entry, $source ) {
		if ( isset( self::$prefixRewrites[$source] ) && isset( $entry[0] ) && isset( self::$prefixRewrites[$source][$entry[0]] ) ) {
			$entry[0] = self::$prefixRewrites[$source][$entry[0]];
		}

		if ( !array_key_exists( "iw_prefix", $entry ) ) {
			$entry = array( "iw_prefix" => $entry[0], "iw_url" => $entry[1], "iw_local" => $entry[2] );
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

$maintClass = "DumpInterwiki";
require_once RUN_MAINTENANCE_IF_MAIN;

