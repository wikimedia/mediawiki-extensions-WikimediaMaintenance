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

class BlameStartupRegistry extends Maintenance {
	const COMP_UNKNOWN = 'unknown';

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Returns an overview of registered ResourceLoader modules,'
			. ' attributing their startup cost by component (e.g. which extension).' );
		$this->addOption( 'record-stats', 'Send gauges to Graphite (default: display to std out)' );
	}

	public function execute() {
		global $IP;

		$rl = MediaWikiServices::getInstance()->getResourceLoader();
		$context = new ResourceLoaderContext( $rl, new FauxRequest( [] ) );
		$moduleNames = $rl->getModuleNames();
		echo "Checking " . count( $moduleNames ) . " modules...\n\n";

		$overview = [
			self::COMP_UNKNOWN => [ 'modules' => 0, 'bytes' => 0, 'names' => [] ],
		];
		$totalCount = 0;
		$totalBytes = 0;

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
			if ( $module instanceof ResourceLoaderStartUpModule ) {
				continue;
			}

			// Approximate what ResourceLoaderStartUpModule does
			try {
				$versionHash = $module->getVersionHash( $context );
			} catch ( Exception $e ) {
				// Ignore
				$versionHash = '';
			}
			// Use index number only, and in a way that's stable over time
			// (so round down in the component's favour, by starting at 0)
			$deps = array_keys( $module->getDependencies( $context ) );
			$group = $module->getGroup() === null ? null : 10;
			$source = $module->getSource() === 'local' ? null : $module->getSource();
			$skipFn = $module->getSkipFunction();
			if ( $skipFn !== null ) {
				$skipFn = ResourceLoader::filter( 'minify-js', $skipFn );
			}
			$registration = [ $name, $versionHash, $deps, $group, $source, $skipFn ];
			self::trimArray( $registration );

			$bytes = strlen( json_encode( $registration ) );

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
					'valueParsers.ValueParserStore', 'valueParsers.parsers', 'vue2', 'vuex'
				] )
			) {
				$component = 'Wikibase';
			} else {
				$component = self::COMP_UNKNOWN;
				$overview[$component]['names'][] = $name;
			}
			$overview[$component]['modules'] = ( $overview[$component]['modules'] ?? 0 ) + 1;
			$overview[$component]['bytes'] = ( $overview[$component]['bytes'] ?? 0 ) + $bytes;
			$totalCount += 1;
			$totalBytes += $bytes;
		}

		// Measure the internal JS code as special component
		$startupJs = file_get_contents( "$IP/resources/src/startup/startup.js" )
			. file_get_contents( "$IP/resources/src/startup/mediawiki.js" )
			. file_get_contents( "$IP/resources/src/startup/mediawiki.requestIdleCallback.js" );
		// ... make variables constant,
		$startupJs = preg_replace( '/\$VARS\.[a-zA-Z]+/', 'null', $startupJs );
		// ... and strip code substitutions.
		$startupJs = preg_replace( '/\$CODE\.[a-zA-Z]+\(\);/', '', $startupJs );
		$startupJs = ResourceLoader::filter( 'minify-js', $startupJs, [ 'cache' => false ] );
		$startupJsBytes = strlen( gzencode( $startupJs, 9 ) );
		unset( $startupJs );
		$overview['startup_js']['modules'] = 0;
		$overview['startup_js']['bytes'] = $startupJsBytes;
		$totalBytes += $startupJsBytes;

		uasort( $overview, function ( $a, $b ) {
			return $b['bytes'] - $a['bytes'];
		} );

		echo "| Component | Modules | Bytes\n";
		echo "|-- |-- |--\n";
		foreach ( $overview as $component => $info ) {
			$moduleStr = $info['modules'];
			$byteStr = number_format( $info['bytes'] );
			echo sprintf( "| %-20s | %5s | %8s\n",
				$component,
				$moduleStr,
				$byteStr
			);
		}

		if ( $overview[self::COMP_UNKNOWN]['names'] ) {
			echo "\n";
			echo "Unknown component: " . implode( ", ", $overview[self::COMP_UNKNOWN]['names'] );
			echo "\n";
		}

		if ( $this->hasOption( 'record-stats' ) ) {
			echo "\n";
			echo "Sending stats to Graphite...\n";
			$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
			$wiki = wfWikiId();
			foreach ( $overview as $component => $info ) {
				if ( $info['modules'] > 0 ) {
					$stats->gauge(
						sprintf( 'resourceloader_startup_modules.%s.%s',
							$wiki, $component
						),
						$info['modules']
					);
				}
				if ( $info['bytes'] > 0 ) {
					$stats->gauge(
						sprintf( 'resourceloader_startup_bytes.%s.%s',
							$wiki, $component
						),
						$info['bytes']
					);
				}
			}
			$stats->gauge(
				sprintf( 'resourceloader_startup_modules_total.%s', $wiki ),
				$totalCount
			);
			$stats->gauge(
				sprintf( 'resourceloader_startup_bytes_total.%s', $wiki ),
				$totalBytes
			);
			echo "Done!\n";
		}
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
