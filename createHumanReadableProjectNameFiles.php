<?php
/**
 * @defgroup Wikimedia Wikimedia
 */

/**
 * Generates i18n files for names of each Wikimedia project, in the specified directory
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

class CreateHumanReadableProjectNameFiles extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Builds i18n files for translating names of Wikimedia projects';

		$this->addOption( 'directory', 'Output directory where files should be created', true, true );
	}

	/**
	 * Gets actual language code (not necessarily the same as subdomain, even if there is
	 * a language subdomain)
	 *
	 * @param string $projURL URL of root of domain (e.g. https://en.wikipedia.org)
	 * @return Language code, or null on failure
	 */
	private function getLanguageCode( $projURL ) {
		$url = "$projURL/w/api.php?action=query&meta=siteinfo&format=json";
		$responseText = Http::get( $url );
		$response = FormatJson::decode( $responseText, true );
		if ( isset( $response['query']['general']['lang'] ) ) {
			return $response['query']['general']['lang'];
		} else {
			return null;
		}
	}

	private function createQQQ( $projName, $projURL ) {
		$languageCode = $this->getLanguageCode( $projURL );

		return "{{ProjectNameDocumentation|url=$projURL|name=$projName|language=$languageCode}}";
	}

	public function execute() {
		$outEn = [];
		$outQqq = [];
		$counter = 0;
		$info = $this->getSitematrixFromAPI();
		$languages = LanguageNames::getNames( 'en' );

		// Wikis that have messages in WikimediaMessages
		$messageMatrix = [
			// code => project name
			"wiki" => wfMessage( "wikibase-otherprojects-wikipedia" )->text(),
			"wiktionary" => "Wiktionary", // No message for this!?
			"wikibooks" => wfMessage( "wikibase-otherprojects-wikibooks" )->text(),
			"wikiquote" => wfMessage( "wikibase-otherprojects-wikiquote" )->text(),
			"wikinews" => wfMessage( "wikibase-otherprojects-wikinews" )->text(),
			"wikisource" => wfMessage( "wikibase-sitelinks-wikisource" )->text(),
			"wikiversity" => "Wikiversity", // No message for this, either!
			"wikivoyage" => wfMessage( "wikibase-otherprojects-wikivoyage" )->text(),
		];

		$failedLangWikis = [
			"azbwiki" => "South Azerbaijani Wikipedia",
			"be_x_oldwiki" => "Belarusian (Taraškievica) Wikipedia",
			"bhwiki" => "Bihari Wikipedia",
			"bhwiktionary" => "Bihari Wiktionary",
			"bxrwiki" => "Buryat Wikipedia",
			"lbewiki" => "Laki Wikipedia",
			"mowiki" => "Moldovan Wikipedia",
			"mowiktionary" => "Moldovan Wiktionary",
			"roa_tarawiki" => "Tarandíne Wikipedia",
			"zh_min_nanwiki" => "Min Nan Wikipedia",
			"zh_min_nanwiktionary" => "Min Nan Wiktionary",
			"zh_min_nanwikibooks" => "Min Nan Wikibooks",
			"zh_min_nanwikiquote" => "Min Nan Wikiquote",
			"zh_min_nanwikisource" => "Min Nan Wikisource",
		];

		// SPECIAL WIKIS
		$exceptions = [
			// code => full message
			"arbcom-de" => "German Wikipedia Arbitration Committee",
			"arbcom-en" => "English Wikipedia Arbitration Committee",
			"arbcom-fi" => "Finnish Wikipedia Arbitration Committee",
			"arbcom-nl" => "Dutch Wikipedia Arbitration Committee",
			"betawikiversity" => "Wikiversity Beta",
			"commons" => wfMessage( "wikibase-otherprojects-commons" )->text(),
			"mediawiki" => wfMessage( "wikibase-otherprojects-mediawiki" )->text(),
			"meta" => wfMessage( "wikibase-otherprojects-meta" )->text(),
			"nostalgia" => "Nostalgia Wikipedia",
			"testwikidata" => "Wikidata Test Wiki",
			"wikidata" => wfMessage( "wikibase-otherprojects-wikidata" )->text(),
			"sources" => wfMessage( "wikibase-otherprojects-wikisource" )->text(),
			"species" => wfMessage( "wikibase-sitelinks-sitename-species" )->text(),
			"strategy" => "Strategic Planning",
			"ten" => "Wikipedia 10",
			"test" => "Test Wikipedia",
			"test2" => "Test2 Wikipedia",
			"zero" => "Wikipedia Zero",
			"board" => "Wikimedia Board Wiki",
			"labs" => "Wikitech",
			"labtest" => "Wikitech Test Wiki",
			"wg-en" => "English Wikipedia Working Group",
			"wikimania2005" => "Wikimania 2005 Wiki",
			"wikimania2006" => "Wikimania 2006 Wiki",
			"wikimania2007" => "Wikimania 2007 Wiki",
			"wikimania2008" => "Wikimania 2008 Wiki",
			"wikimania2009" => "Wikimania 2009 Wiki",
			"wikimania2010" => "Wikimania 2010 Wiki",
			"wikimania2011" => "Wikimania 2011 Wiki",
			"wikimania2012" => "Wikimania 2012 Wiki",
			"wikimania2013" => "Wikimania 2013 Wiki",
			"wikimania2014" => "Wikimania 2014 Wiki",
			"wikimania2015" => "Wikimania 2015 Wiki",
			"wikimania2016" => "Wikimania 2016 Wiki",
			"wikimania2017" => "Wikimania 2017 Wiki",
			"bdwikimedia" => "Wikimedia Bangladesh",
			"brwikimedia" => "Wikimedia Brazil",
			"cnwikimedia" => "Wikimedia China",
			"dkwikimedia" => "Wikimedia Denmark",
			"etwikimedia" => "Wikimedia Estonia",
			"fiwikimedia" => "Wikimedia Finland",
			"ilwikimedia" => "Wikimedia Israel",
			"mkwikimedia" => "Wikimedia Macedonia",
			"mxwikimedia" => "Wikimedia Mexico",
			"nlwikimedia" => "Wikimedia Netherlands",
			"nowikimedia" => "Wikimedia Norway",
			"nzwikimedia" => "Wikimedia New Zealand",
			"plwikimedia" => "Wikimedia Poland",
			"rswikimedia" => "Wikimedia Serbia",
			"ruwikimedia" => "Wikimedia Russia",
			"sewikimedia" => "Wikimedia Sweden",
			"trwikimedia" => "Wikimedia Turkey",
			"uawikimedia" => "Wikimedia Ukraine",
			"ukwikimedia" => "Wikimedia UK",
		];

		// Go over the wikis and fill in the information
		foreach ( $info as $i => $languageGroup ) {
			if ( !is_numeric( $i ) ) {
				continue;
			}

			$langCode = $languageGroup['code'];
			// Go by each site
			$sites = $languageGroup['site'];
			for ( $j = 0; $j < count( $sites ); $j++ ) {
				// Wiki info
				$dbname = $sites[ $j ]['dbname'];
				$url = $sites[ $j ]['url'];

				if ( array_key_exists( $dbname, $failedLangWikis ) ) {
					$name = $failedLangWikis[ $dbname ];
				} else {
					$sitecode = $sites[ $j ]['code'];
					$sitename = !empty( $messageMatrix[ $sitecode ] ) ? $messageMatrix[ $sitecode ] : $sites[ $j ]['sitename'];
					// Language conversion
					$lang = $languages[ $langCode ];

					$name = $lang . " " . $sitename;
				}

				// Output the line
				$outEn[ "project-localized-name-" . $dbname ] =  $name;
				$outQqq[ "project-localized-name-" . $dbname ] =  $this->createQQQ( $name, $url );
				$table[$dbname] = [ 'name' => $name, 'url' => $url ];

				$counter++;
			}
		}

		// Go over the "special" wikis (exceptions)
		$specials = $info[ "specials" ];
		for ( $i = 0; $i < count( $specials ); $i++ ) {
			$dbname = $specials[ $i ]['dbname'];
			$url = $specials[ $i ]['url'];
			$sitecode = $specials[ $i ]['code'];
			// Go over exceptions
			if ( array_key_exists( $sitecode, $exceptions ) ) {
				$sitename = $exceptions[ $sitecode ];
			} else {
				// Output the line
				$sitename = $specials[ $i ]['sitename'];
			}

			// Output the line
			$outEn[ "project-localized-name-" . $dbname ] =  $sitename;
			$outQqq[ "project-localized-name-" . $dbname ] =  $this->createQQQ( $sitename, $url );
			$table[$dbname] = [ 'name' => $sitename, 'url' => $url ];

			$counter++;
		}

		// Output
		$this->output( "Processed $counter sites" );

		$directory = $this->getOption( 'directory' );

		$enJson = $directory . '/en.json';
		file_put_contents( $enJson, FormatJson::encode( $outEn, "\t", FormatJson::ALL_OK ) );

		$qqqJson = $directory . '/qqq.json';
		file_put_contents( $qqqJson, FormatJson::encode( $outQqq, "\t", FormatJson::ALL_OK ) );
	}

	private function getSitematrixFromAPI() {
		$url = "https://en.wikipedia.org/w/api.php?action=sitematrix&format=json&formatversion=2";

		$response = FormatJson::decode( Http::get( $url ), true );

		return $response['sitematrix'];
	}
}

$maintClass = "CreateHumanReadableProjectNameFiles";
require_once RUN_MAINTENANCE_IF_MAIN;
