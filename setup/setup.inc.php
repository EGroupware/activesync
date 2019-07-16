<?php
/**
 * EGroupware - eSync - ActiveSync protocol based on Z-Push
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package esync
 * @subpackage setup
 */

/* Basic information about this app */
$setup_info['activesync']['name']      = 'activesync';
$setup_info['activesync']['title']     = 'eSync';
$setup_info['activesync']['version']   = '19.1';
$setup_info['activesync']['enable']    = 2;
$setup_info['activesync']['app_order'] = 99;

$setup_info['activesync']['autoinstall'] = true;	// install automatically on update

$setup_info['activesync']['author'] = array(
		'name' => 'Z-Push Project',
		'url'  => 'http://z-push.org/'
	);
$setup_info['activesync']['note']   = 'ActiveSync protocol for EGroupware based on Z-Push';
$setup_info['activesync']['license']  = 'GPL';	// GPL2 as in class.about.inc.php
$setup_info['activesync']['description'] =
	'This module allows you to syncronize your ActiveSync enabled device.';

$setup_info['activesync']['maintainer'] = array(
		'name'  => 'EGroupware GmbH',
		'email' => 'info@egroupware.org',
	);

$setup_info['activesync']['tables'] = array('egw_zpush_states','egw_zpush_users','egw_zpush_settings');

$setup_info['activesync']['hooks']['preferences']	= 'activesync_hooks::menus';
$setup_info['activesync']['hooks']['settings']	= 'activesync_hooks::settings';
$setup_info['activesync']['hooks']['verify_settings']	= 'activesync_hooks::verify_settings';

/* Dependencies for this app to work */
$setup_info['activesync']['depends'][] = array(
	 'appname'  => 'api',
	 'versions' => Array('19.1')
);
