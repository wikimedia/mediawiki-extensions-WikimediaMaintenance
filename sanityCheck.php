<?php
/**
 * Pre-deployment sanity check.
 *
 * A quick integration test to be done during scap: execute a parser cache hit.
 */

// Use CommandLineInc.php instead of WikimediaMaintenance so that the code
// can be parsed after the autoloader is started, so that we can have
// SanityCheckRequest in the same file as the execution code.
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../..';
}

// Require base maintenance class
require_once "$IP/maintenance/CommandLineInc.php";

class SanityCheckRequest extends FauxRequest {
	/** @var Title */
	public $title;

	public function __construct() {
		$this->title = Title::newMainPage();

		parent::__construct( [
			'title' => $this->title->getPrefixedDBkey()
		] );
	}

	public function getRequestURL() {
		return $this->title->getFullURL( '', false, PROTO_CANONICAL );
	}
}

/**
 * @return never
 */
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
