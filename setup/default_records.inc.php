<?php
/**
 * EGroupware - eSync - ActiveSync protocol based on Z-Push
 *
 * @link www.egroupware.org
 * @author rb(at)stylite.de
 * @package esync
 * @subpackage setup
 * @version $Id$
 */

// give Default and Admins group rights for E-Push app
foreach(array('Default' => 'Default','Admins' => 'Admin') as $account_lid => $name)
{
	$account_id = $GLOBALS['egw_setup']->add_account($account_lid,$name,'Group',False,False);
	$GLOBALS['egw_setup']->add_acl('activesync','run',$account_id);
}
