<?php
/**
 * EGroupware - eSync - ActiveSync protocol based on Z-Push
 *
 * http://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package activesync
 * @subpackage setup
 */

/**
 * Update from 11.1
 *
 * @return string
 */
function activesync_upgrade0_1()
{
	return $GLOBALS['setup_info']['activesync']['currentver'] = '16.1';
}

/**
 * Update from 14.x
 *
 * @return string
 */
function activesync_upgrade14_1()
{
	return $GLOBALS['setup_info']['activesync']['currentver'] = '16.1';
}

/**
 * Creating sqlstatemaschine tables
 *
 * @return string
 */
function activesync_upgrade16_1()
{
	$GLOBALS['egw_setup']->oProc->CreateTable('egw_zpush_states', array(
		'fd' => array(
			'id_state' => array('type' => 'auto','precision' => '4','nullable' => False),
			'device_id' => array('type' => 'ascii','precision' => '50','nullable' => False),
			'uuid' => array('type' => 'ascii','precision' => '50'),
			'state_type' => array('type' => 'ascii','precision' => '50'),
			'counter' => array('type' => 'int','precision' => '4'),
			'state_data' => array('type' => 'blob'),
			'created_at' => array('type' => 'timestamp','meta' => 'timestamp','nullable' => False),
			'updated_at' => array('type' => 'timestamp','meta' => 'timestamp','nullable' => False)
		),
		'pk' => array('id_state'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array(array('device_id','uuid','state_type','counter'))
	));

	$GLOBALS['egw_setup']->oProc->CreateTable('egw_zpush_users',array(
		'fd' => array(
			'username' => array('type' => 'varchar','precision' => '50','nullable' => False),
			'device_id' => array('type' => 'ascii','precision' => '50','nullable' => False)
		),
		'pk' => array(),
		'fk' => array(),
		'ix' => array(),
		'uc' => array(array('username','device_id'))
	));

	$GLOBALS['egw_setup']->oProc->CreateTable('egw_zpush_settings',array(
		'fd' => array(
			'key_name' => array('type' => 'ascii','precision' => '50','nullable' => False),
			'key_value' => array('type' => 'varchar','precision' => '50','nullable' => False),
			'created_at' => array('type' => 'timestamp','meta' => 'timestamp','nullable' => False),
			'updated_at' => array('type' => 'timestamp','meta' => 'timestamp','nullable' => False)
		),
		'pk' => array('key_name'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	));

	return $GLOBALS['setup_info']['activesync']['currentver'] = '16.1.001';
}

/**
 * Bump version to 17.1
 *
 * @return string
 */
function activesync_upgrade16_1_001()
{
	return $GLOBALS['setup_info']['activesync']['currentver'] = '17.1';
}

/**
 * Bump version to 19.1
 *
 * @return string
 */
function activesync_upgrade17_1()
{
	return $GLOBALS['setup_info']['activesync']['currentver'] = '19.1';
}

/**
 * Bump version to 20.1
 *
 * @return string
 */
function activesync_upgrade19_1()
{
	return $GLOBALS['setup_info']['activesync']['currentver'] = '20.1';
}

/**
 * Bump version to 21.1
 *
 * @return string
 */
function activesync_upgrade20_1()
{
	return $GLOBALS['setup_info']['activesync']['currentver'] = '21.1';
}

/**
 * Bump version to 23.1
 *
 * @return string
 */
function activesync_upgrade21_1()
{
	return $GLOBALS['setup_info']['activesync']['currentver'] = '23.1';
}