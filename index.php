<?php
/**
 * EGroupware - eSync - ActiveSync protocol based on Z-Push
 *
 * @link http://www.egroupware.org
 * @package esync
 * @author Ralf Becker <rb@stylite.de>
 * @author Stylite AG <info@stylite.de>
 * @author Philip Herbert <philip@knauber.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

// disable globally enabled compression, as this causes issues with some devices
// z-push handles output compression
ini_set("zlib.output_compression",0);

if (!isset($GLOBALS['egw_info']))
{
	$GLOBALS['egw_info'] = array(
		'flags' => array(
			'disable_Template_class'  => true,
			'noheader'                => true,
			'currentapp'              => 'login',
			'no_exception_handler' => 'errorlog',	// only logs exceptions
	));

	require(__DIR__.'/../header.inc.php');

	// turn off output buffering turned on in our header.inc.php, as z-push cant kope with it
	// (ItemOperations of big attachments send wrong size Content-Length header)
	while(ob_end_clean()) { }
}

// EGroupware specific ZPush version, overwriting version.php from stock ZPush
define('ZPUSH_VERSION', 'EGroupware-'.EGroupware\Api\Framework::api_version());

// following code is necessary to use an unchanged z-push2 package installed in vendor/z-push/z-push
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/vendor/z-push/z-push/src/index.php';
chdir(__DIR__.'/vendor/z-push/z-push/src');
define('ZPUSH_CONFIG', __DIR__.'/inc/config.php');
include(__DIR__.'/vendor/z-push/z-push/src/index.php');
