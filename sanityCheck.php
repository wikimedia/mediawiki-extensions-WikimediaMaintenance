<?php
/**
 * Pre-deployment sanity check.
 *
 * A quick integration test to be done during scap: execute a parser cache hit.
 */

// Use WikimediaCommandLine.inc instead of WikimediaMaintenance so that the code
// can be parsed after the autoloader is started, so that we can have
// SanityCheckRequest in the same file as the execution code.
require_once __DIR__ .'/WikimediaCommandLine.inc';

class SanityCheckRequest extends FauxRequest {
	public $title;

	function __construct() {
		$this->title = Title::newMainPage();

		parent::__construct( array(
			'title' => $this->title->getPrefixedDBkey()
		) );
	}

	function getRequestURL() {
		return $this->title->getFullURL( '', false, PROTO_CANONICAL );
	}
}

function doSanityCheck() {
	$req = new SanityCheckRequest;
	$context = new RequestContext;
	$context->setRequest( $req );
	$main = new MediaWiki( $context );
	ob_start();
	$main->run();
	$result = ob_get_contents();
	ob_end_clean();

	if ( strpos( $result, '<!-- Served by' ) !== false ) {
		exit( 0 );
	} else {
		echo "sanityCheck.php failed string match test\n";
		exit( 1 );
	}
}

doSanityCheck();
