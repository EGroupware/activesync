<?php
/**
 * EGroupware - eSync - ActiveSync protocol based on Z-Push
 *
 * @link http://www.egroupware.org
 * @package esync
 * @author Ralf Becker <rb@egroupware.org>
 * @author EGroupware GmbH <info@egroupware.org>
 * @author Philip Herbert <philip@knauber.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
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

// use Composer installed egroupware/z-push-dev from global vendor directory
$_SERVER['SCRIPT_FILENAME'] = EGW_SERVER_ROOT.'/vendor/egroupware/z-push-dev/src/index.php';
chdir(dirname($_SERVER['SCRIPT_FILENAME']));
define('ZPUSH_CONFIG', __DIR__.'/inc/config.php');
include($_SERVER['SCRIPT_FILENAME']);
