<?php
/**
 * A simple class representing a single WMF wiki.
 *
 * Used by dumpInterwiki.php.
 */
class WMFSite {
	public string $suffix;
	public string $prefix;
	public string $url;

	public function __construct( string $suffix, string $prefix, string $url ) {
		$this->suffix = $suffix;
		$this->prefix = $prefix;
		$this->url = $url;
	}

	public function getURL( string $lang, string $urlprotocol ): string {
		$xlang = str_replace( '_', '-', $lang );
		return "$urlprotocol//$xlang.{$this->url}/wiki/\$1";
	}
}
