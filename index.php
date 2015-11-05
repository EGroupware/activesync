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

// following code is necessary to use an unchanged z-push2 package installed in vendor/z-push/z-push
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/vendor/z-push/z-push/src/index.php';
chdir(__DIR__.'/vendor/z-push/z-push/src');
define('ZPUSH_CONFIG', __DIR__.'/inc/config.php');
include('vendor/z-push/z-push/src/index.php');
