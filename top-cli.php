#!/opt/local/bin/php56 -d error_reporting=6135
<?php
/**
 * EGroupware: eSync - top based on z-push-top
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package esync
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

if (php_sapi_name() !== 'cli')	// security precaution: forbit calling z-push-top as web-page
{
	die('<h1>top-cli.php must NOT be called as web-page --> exiting !!!</h1>');
}

// include our header
$GLOBALS['egw_info'] = array(
	'flags' => array(
		'disable_Template_class'  => true,
		'noheader'                => true,
		'currentapp'              => 'login',
		'no_exception_handler' => 'errorlog',	// only logs exceptions
));

require(__DIR__.'/../header.inc.php');

while(ob_end_flush()) {}

if (!file_exists($GLOBALS['egw_info']['server']['files_dir']) ||
	!is_writable($GLOBALS['egw_info']['server']['files_dir']))
{
	if (function_exists('posix_getpwuid') && ($info = posix_getpwuid(fileowner(dirname(dirname($GLOBALS['egw_info']['server']['files_dir']))))))
	{
		$user = $info['name'];
	}
	else
	{
		$user = "(www-data|apache|www)";
	}
	die("top-cli.php must be called with webserver user to have proper access rights to {$GLOBALS['egw_info']['server']['files_dir']}!\n".
		"sudo -u $user ".$_SERVER['argv'][0]."\n\n");
}

// include z-push-top.php with our configuration
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/vendor/z-push/z-push/src/z-push-top.php';
chdir(__DIR__.'/vendor/z-push/z-push/src');
define('ZPUSH_CONFIG', __DIR__.'/inc/config.php');
include('vendor/z-push/z-push/src/z-push-top.php');
