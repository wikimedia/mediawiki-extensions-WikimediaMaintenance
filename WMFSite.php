<?php
/**
 * A simple little class referring to a specific WMF site.
 * @ingroup Maintenance
 */
class WMFSite {
	/** @var string */
	public $suffix;
	/** @var string */
	public $prefix;
	/** @var string */
	public $url;

	/**
	 * @param string $suffix
	 * @param string $prefix
	 * @param string $url
	 */
	public function __construct( $suffix, $prefix, $url ) {
		$this->suffix = $suffix;
		$this->prefix = $prefix;
		$this->url = $url;
	}

	/**
	 * @param string $lang
	 * @param string $urlprotocol
	 * @return string
	 */
	public function getURL( $lang, $urlprotocol ) {
		$xlang = str_replace( '_', '-', $lang );
		return "$urlprotocol//$xlang.{$this->url}/wiki/\$1";
	}
}
