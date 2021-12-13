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
 * @ingroup Wikimedia
 */
use MediaWiki\MediaWikiServices;

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * To test this locally, simply run it without parameters:
 *
 *     $ php extensions/WikimediaMaintenance/blameStartupRegistry.php
 *
 * The extension does not need to be installed first.
 */
class BlameStartupRegistry extends Maintenance {
	private const COMP_UNKNOWN = 'unknown';

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Returns an overview of registered ResourceLoader modules,'
			. ' attributing their startup cost by component (e.g. which extension).' );
		$this->addOption( 'record-stats', 'Send gauges to Graphite (default: display to std out)' );
	}

	public function execute() {
		global $IP;

		$rl = MediaWikiServices::getInstance()->getResourceLoader();
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$context = new ResourceLoaderContext( $rl, new FauxRequest( [
			'lang' => $contLang->getCode(),
			'skin' => 'vector',
		] ) );
		$moduleNames = $rl->getModuleNames();
		echo "Checking " . count( $moduleNames ) . " modules...\n\n";

		$startupBreakdown = [
			self::COMP_UNKNOWN => [ 'modules' => 0, 'bytes' => 0, 'names' => [] ],
		];
		$startupCount = 0;
		$startupBytesTotal = 0;
		$contentBreakdown = [
			/* module name => [ component => str, transferSize => int, decodedSize => int ] */
		];

		$coreModuleNames = array_keys( require "$IP/resources/Resources.php" );
		$extModuleNames = []; // from module name to extension name

		// Approximate ExtensionRegistry and ExtensionProcessor
		$extReg = ExtensionRegistry::getInstance();
		foreach ( $extReg->getAllThings() as $extName => $extData ) {
			$json = json_decode( file_get_contents( $extData['path'] ), /* assoc = */ true );
			$modules = $json['ResourceModules'] ?? [];
			foreach ( $modules as $moduleName => $moduleInfo ) {
				$extModuleNames[$moduleName] = $extName;
			}
		}

		try {
			$rl->preloadModuleInfo( $moduleNames, $context );
		} catch ( Exception $e ) {
			// Ignore
		}

		foreach ( $moduleNames as $name ) {
			$module = $rl->getModule( $name );
			if ( !$module || $module instanceof ResourceLoaderStartUpModule ) {
				continue;
			}

			// Approximate what ResourceLoaderStartUpModule does
			try {
				$versionHash = $module->getVersionHash( $context );
			} catch ( Exception $e ) {
				// Ignore
				$versionHash = '';
			}

			// Approximate ResourceLoader::makeLoaderRegisterScript() for dependencies.
			//
			// Turn `[str,str,str]` dependencies into `[101,102,103]` like production.
			// But, instead of using the offsets of the complete registry,
			// we approximate it with array keys of the local dependency array.
			// This has the benefit of being stable over time, instead of varying
			// based on relative position in the registry. It also rounds down
			// in the component's favour by always starting at 0.
			$deps = array_keys( $module->getDependencies( $context ) );

			// Approximate ResourceLoaderStartUpModule::getGroupId().
			// The string is not used in production. Replace all non-null values
			// with a fixed size digit.
			$group = $module->getGroup() === null ? null : 10;

			$source = $module->getSource() === 'local' ? null : $module->getSource();
			$skipFn = $module->getSkipFunction();
			if ( $skipFn !== null ) {
				$skipFn = ResourceLoader::filter( 'minify-js', $skipFn );
			}
			$registration = [ $name, $versionHash, $deps, $group, $source, $skipFn ];
			self::trimArray( $registration );

			$startupBytes = strlen( json_encode( $registration ) );

			// Approximate transfer size per module.
			// The true size in production will be smaller than our estimate here, because
			// in production modules can be freely combined with other unrelated modules
			// in a single batch request, and web servers will apply response compression
			// across module boundaries. That's great for performance, but also means that
			// each individual byte is not attributable to a (single) module, and the cost
			// reduction can vary highly depending on what features the user has interacted
			// with in the past.
			$contentContext = new DerivativeResourceLoaderContext( $context );
			// Generate a pure only=styles response for styles modules,
			// and an mw.loader.implement() response for general modules.
			$contentContext->setOnly(
				$module->getType() === ResourceLoaderModule::LOAD_STYLES
					? ResourceLoaderModule::TYPE_STYLES
					: ResourceLoaderModule::TYPE_COMBINED
			);
			$content = $rl->makeModuleResponse( $contentContext, [ $name => $module ] );
			$contentTransferSize = strlen( gzencode( $content, 9 ) );
			$contentDecodedSize = strlen( $content );

			if ( in_array( $name, $coreModuleNames, true ) ) {
				$component = 'MediaWiki core';
			} elseif ( isset( $extModuleNames[$name] ) ) {
				$component = $extModuleNames[$name];
			} elseif ( strpos( $name, 'ext.gadget.' ) === 0 ) {
				$component = 'user_gadgets';
				// Some exenstension (also) have modules registered outside ExtensionRegistry
			} elseif ( strpos( $name, 'ext.centralauth.' ) === 0 ) {
				$component = 'CentralAuth';
			} elseif ( strpos( $name, 'ext.echo.' ) === 0 ) {
				$component = 'Echo';
			} elseif ( strpos( $name, 'ext.guidedTour.' ) === 0 ) {
				$component = 'GuidedTour';
			} elseif ( strpos( $name, 'ext.pageTriage.' ) === 0 ) {
				$component = 'PageTriage';
			} elseif ( strpos( $name, 'mobile.' ) === 0 ) {
				$component = 'MobileFrontend';
			} elseif ( strpos( $name, 'skins.monobook.' ) === 0 ) {
				$component = 'MonoBook';
			} elseif (
				preg_match( '/^((jquery\.)?(wikibase|valueview)\b|mw\.config\.values\.wb)/', $name )
			) {
				// Does not use ExtensionRegistry yet
				$component = 'Wikibase';
			} elseif (
				in_array( $name, [
					'dataValues', 'dataValues.DataValue', 'dataValues.TimeValue', 'dataValues.values',
					'jquery.animateWithEvent', 'jquery.event.special.eachchange', 'jquery.inputautoexpand',
					'jquery.ui.commonssuggester', 'jquery.ui.languagesuggester', 'jquery.ui.suggester',
					'jquery.util.getDirectionality', 'promise-polyfill', 'util.ContentLanguages',
					'util.Extendable', 'util.MessageProvider', 'util.MessageProviders', 'util.Notifier',
					'util.highlightSubstring', 'util.inherit', 'valueFormatters', 'valueParsers',
					'valueParsers.ValueParserStore', 'valueParsers.parsers',
				] )
			) {
				$component = 'Wikibase';
			} else {
				$component = self::COMP_UNKNOWN;
				$startupBreakdown[$component]['names'][] = $name;
			}

			$startupBreakdown[$component]['modules'] = ( $startupBreakdown[$component]['modules'] ?? 0 ) + 1;
			$startupBreakdown[$component]['startupBytes'] =
				// @phan-suppress-next-line PhanPossiblyUndeclaredVariable https://github.com/phan/phan/issues/4617
				( $startupBreakdown[$component]['startupBytes'] ?? 0 ) + $startupBytes;
			$startupCount += 1;
			$startupBytesTotal += $startupBytes;

			$contentBreakdown[$name] = [
				'component' => $component,
				'transferSize' => $contentTransferSize,
				'decodedSize' => $contentDecodedSize,
			];
		}

		// Measure the internal JS code as its own special component
		$startupJs = $this->getInternalStartupJs( $rl, $context );
		$startupJs = ResourceLoader::filter( 'minify-js', $startupJs, [ 'cache' => false ] );
		$startupJsBytes = strlen( gzencode( $startupJs, 9 ) );
		unset( $startupJs );
		$startupBreakdown['startup_js']['modules'] = 0;
		$startupBreakdown['startup_js']['startupBytes'] = $startupJsBytes;
		$startupBytesTotal += $startupJsBytes;

		uasort( $startupBreakdown, static function ( $a, $b ) {
			return $b['startupBytes'] - $a['startupBytes'];
		} );

		echo "| Component | Modules | Startup bytes\n";
		echo "|-- |-- |--\n";
		foreach ( $startupBreakdown as $component => $info ) {
			$moduleStr = $info['modules'];
			$byteStr = number_format( $info['startupBytes'] );
			echo sprintf( "| %-20s | %5s | %8s B\n",
				$component,
				$moduleStr,
				$byteStr
			);
		}

		if ( $startupBreakdown[self::COMP_UNKNOWN]['names'] ) {
			echo "\n";
			echo "Unknown component: " . implode( ", ", $startupBreakdown[self::COMP_UNKNOWN]['names'] );
			echo "\n";
		}

		echo "\n";
		echo "| Module | transferSize | decodedSize\n";
		echo "|-- |-- |--\n";
		foreach ( $contentBreakdown as $name => $info ) {
			echo sprintf( "| %-50s | %8s B | %8s B\n",
				$name,
				number_format( $info['transferSize'] ),
				number_format( $info['decodedSize'] )
			);
		}
		echo "\n";

		if ( $this->hasOption( 'record-stats' ) ) {
			echo "\n";
			echo "Sending stats...\n";
			$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
			$wiki = wfWikiId();
			foreach ( $startupBreakdown as $component => $info ) {
				if ( $info['modules'] > 0 ) {
					$stats->gauge(
						sprintf( 'resourceloader_startup_modules.%s.%s',
							$wiki, $component
						),
						$info['modules']
					);
				}
				if ( $info['startupBytes'] > 0 ) {
					$stats->gauge(
						sprintf( 'resourceloader_startup_bytes.%s.%s',
							$wiki, $component
						),
						$info['startupBytes']
					);
				}
			}
			$stats->gauge(
				sprintf( 'resourceloader_startup_modules_total.%s', $wiki ),
				$startupCount
			);
			$stats->gauge(
				sprintf( 'resourceloader_startup_bytes_total.%s', $wiki ),
				$startupBytesTotal
			);
			foreach ( $contentBreakdown as $name => $info ) {
				$statName = strtr( $name, '.', '_' );
				$stats->gauge(
					sprintf( 'resourceloader_module_transfersize_bytes.%s.%s.%s',
						$wiki, $info['component'], $statName
					),
					$info['transferSize']
				);
				$stats->gauge(
					sprintf( 'resourceloader_module_decodedsize_bytes.%s.%s.%s',
						$wiki, $info['component'], $statName
					),
					$info['decodedSize']
				);
			}

			echo "Done!\n";
		}
	}

	/**
	 * Get the portion of the startup module response that is constant.
	 *
	 * This is for startup.js and mw.loader client, without any module registrations.
	 *
	 * @param ResourceLoader $rl
	 * @param ResourceLoaderContext $context
	 * @return string JavaScript code
	 */
	private function getInternalStartupJs( ResourceLoader $rl, ResourceLoaderContext $context ): string {
		// Avoid hardcoding which files are included by ResourceLoaderStartUpModule::getScript.
		// Instead, subclass it and stub out getModuleRegistrations().
		$startupModule = new class() extends ResourceLoaderStartUpModule {

			public function getModuleRegistrations( ResourceLoaderContext $context ): string {
				return '';
			}

		};
		$startupModule->setConfig( $rl->getConfig() );

		// The modules=startup request requires use of only=scripts
		$derivative = new DerivativeResourceLoaderContext( $context );
		$derivative->setOnly( 'scripts' );

		return $startupModule->getScript( $derivative );
	}

	private static function trimArray( array &$array ) {
		$i = count( $array );
		while ( $i-- ) {
			if ( $array[$i] === null || $array[$i] === [] ) {
				unset( $array[$i] );
			} else {
				break;
			}
		}
	}

}

$maintClass = BlameStartupRegistry::class;
require_once RUN_MAINTENANCE_IF_MAIN;
