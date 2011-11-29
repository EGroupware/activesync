<?php
/**
 * EGroupware: eSync - ActiveSync protocol based on Z-Push: hooks: eg. preferences
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package esync
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

/**
 * eSync hooks: eg. preferences
 */
class activesync_hooks
{
	/**
	 * Show E-Push preferences link in preferences
	 *
	 * @param string|array $args
	 */
	public static function menus($args)
	{
		$appname = 'activesync';
		$location = is_array($args) ? $args['location'] : $args;

		if ($location == 'preferences')
		{
			$file = array(
				'Preferences'     => egw::link('/index.php','menuaction=preferences.uisettings.index&appname='.$appname),
			);
			if ($location == 'preferences')
			{
				display_section($appname,$file);
			}
			else
			{
				display_sidebox($appname,lang('Preferences'),$file);
			}
		}
	}

	/**
	 * Populates $settings for the preferences
	 *
	 * Settings are gathered from all plugins and grouped in sections for each application/plugin.
	 *
	 * @param array|string $hook_data
	 * @return array
	 */
	static function settings($hook_data)
	{
		$settings = array();

		set_include_path(EGW_SERVER_ROOT.'/activesync' . PATH_SEPARATOR . get_include_path());
		require_once EGW_INCLUDE_ROOT.'/activesync/backend/egw.php';
		$backend = new BackendEGW();

		$last_app = '';
		foreach($backend->settings($hook_data) as $name => $data)
		{
			// check if we are on a different app --> create a section header
			list($app) = explode('-',$name);
			if ($last_app !== $app && isset($GLOBALS['egw_info']['apps'][$app]))
			{
				$settings[] = array(
					'type'  => 'section',
					'title' => $app,
				);
				$last_app = $app;
			}
			$settings[$name] = $data;
		}
		if ($GLOBALS['type'] === 'forced' || $GLOBALS['type'] === 'user' &&
			$GLOBALS['egw_info']['user']['preferences']['activesync']['delete-profile'] !== 'never')
		{
			// check if our verify_settings hook is registered, register if if not
			$hooks = $GLOBALS['egw']->hooks;
			if (!isset($hooks->locations['verify_settings']['activesync']))
			{
				$f = EGW_SERVER_ROOT . '/activesync/setup/setup.inc.php';
				$setup_info = array($appname => array());
				if(@file_exists($f)) include($f);
				$hooks->register_hooks('activesync', $setup_info['activesync']['hooks']);
				$GLOBALS['egw']->invalidate_session_cache();
			}
			if ($GLOBALS['type'] === 'user')
			{
				$profiles = array();
				if (($dirs = scandir($as_dir=$GLOBALS['egw_info']['server']['files_dir'].'/activesync')))
				{
					foreach($dirs as $dir)
					{
						if (file_exists($as_dir.'/'.$dir.'/device_info_'.$GLOBALS['egw_info']['user']['account_lid']))
						{
							$profiles[$dir] = $dir.' ('.egw_time::to(filectime($as_dir.'/'.$dir)).')';
						}
					}
				}
			}
			else	// allow to force users to NOT be able to delete their profiles
			{
				$profiles = array('never' => lang('Never'));
			}
			$settings[] = array(
				'type'  => 'section',
				'title' => 'Maintenance',
			);
			$settings['delete-profile'] = array(
				'type'   => 'select',
				'label'  => 'Select serverside profile to delete<br>ALWAYS delete account on device first!',
				'name'   => 'delete-profile',
				'help'   => 'Deleting serverside profile removes all traces of previous synchronisation attempts of the selected device.',
				'values' => $profiles,
				'xmlrpc' => True,
				'admin'  => False,
			);
		}
		return $settings;
	}

	/**
	 * Verify settings before storing them
	 *
	 * @param array $hook_data values for keys 'location', 'prefs', 'prefix' and 'type'
	 * @return string|boolean false on success or string with error-message to NOT store preferences
	 */
	static function verify_settings($hook_data)
	{
		if ($hook_data['prefs']['delete-profile'] && preg_match('/^[a-z0-9]+$/',$hook_data['prefs']['delete-profile']) &&
			file_exists($profil=$GLOBALS['egw_info']['server']['files_dir'].'/activesync/'.$hook_data['prefs']['delete-profile']))
		{
			foreach(scandir($profil) as $file)
			{
				unlink($profil.'/'.$file);
			}
			return rmdir($profil) ? lang ('Profil %1 deleted.',$hook_data['prefs']['delete-profile']) :
				lang('Deleting of profil %1 failed!',$hook_data['prefs']['delete-profile']);
		}
	}
}