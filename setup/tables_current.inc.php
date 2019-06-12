<?php
/**
 * eGroupWare - Setup
 * http://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package activesync
 * @subpackage setup
 */


$phpgw_baseline = array(
	'egw_zpush_states' => array(
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
	),
	'egw_zpush_users' => array(
		'fd' => array(
			'username' => array('type' => 'varchar','precision' => '50','nullable' => False),
			'device_id' => array('type' => 'ascii','precision' => '50','nullable' => False)
		),
		'pk' => array(),
		'fk' => array(),
		'ix' => array(),
		'uc' => array(array('username','device_id'))
	),
	'egw_zpush_settings' => array(
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
	)
);
