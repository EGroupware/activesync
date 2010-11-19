<?php
/**
 * eGroupWare - ActiveSync
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package activesync
 * @subpackage activesync
 * @version $Id$
 */

/* Basic information about this app */
$setup_info['activesync']['name']      = 'activesync';
$setup_info['activesync']['title']     = 'ActiveSync';
$setup_info['activesync']['version']   = '0.1';
$setup_info['activesync']['enable']    = 2;
$setup_info['activesync']['app_order'] = 99;

$setup_info['activesync']['author'] = array(
		'name' => 'z-push Project',
		'url'  => 'http://z-push.sourceforge.net/soswp/'
	);
$setup_info['activesync']['note']   = 'ActiveSync interface for eGroupWare based on z-push';
$setup_info['activesync']['license']  = 'GPL v2';
$setup_info['activesync']['description'] =
	'This module allows you to syncronize your ActiveSync enabled device.';

$setup_info['activesync']['maintainer'] = array(
		'name' => 'for eGroupware Backend',
		'email' => ''
	);

/* Dependencies for this app to work */
$setup_info['activesync']['depends'][] = array(
	 'appname'  => 'phpgwapi',
	 'versions' => Array('1.7','1.8','1.9')
);
// installation checks for SyncML
$setup_info['activesync']['check_install'] = array(
	'' => array(
		'func' => 'pear_check',
		'from' => 'ActiveSync',
	),
	'Log' => array(
		'func' => 'pear_check',
		'from' => 'ActiveSync',
	),
);
