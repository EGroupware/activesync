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
	public $public_functions = array(
		'log' => true,
	);

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
		// check if our verify_settings hook is registered, register it if not
		$hooks = $GLOBALS['egw']->hooks;
		if (!isset($hooks->locations['verify_settings']['activesync']))
		{
			$f = EGW_SERVER_ROOT . '/activesync/setup/setup.inc.php';
			$setup_info = array($appname => array());
			if(@file_exists($f)) include($f);
			$hooks->register_hooks('activesync', $setup_info['activesync']['hooks']);
			if (method_exists($GLOBALS['egw'], 'invalidate_session_cache'))
			{
				$GLOBALS['egw']->invalidate_session_cache();
			}
		}
		if ($GLOBALS['type'] === 'user')
		{
			$profiles = $logs = array();
			if (($dirs = scandir($as_dir=$GLOBALS['egw_info']['server']['files_dir'].'/activesync')))
			{
				foreach($dirs as $dir)
				{
					if (file_exists($as_dir.'/'.$dir.'/device_info_'.$GLOBALS['egw_info']['user']['account_lid']))
					{
						$profiles[$dir] = $dir.' ('.egw_time::to(filectime($as_dir.'/'.$dir)).')';

						$logs[$dir.'/debug.txt'] = (file_exists($log = $as_dir.'/'.$dir.'/debug.txt') && !is_link($log) ?
								egw_time::to(filemtime($log)) : lang('disabled')).': '.$dir;
					}
				}
				if ($GLOBALS['egw_info']['user']['apps']['admin'])
				{
					$logs['debug.txt'] = (file_exists($log = $as_dir.'/debug.txt') && !is_link($log) ?
							egw_time::to(filemtime($log)) : lang('disabled')).': '.lang('All users, admin only');
				}
			}
			$link = egw::link('/index.php',array(
				'menuaction' => 'activesync.activesync_hooks.log',
				'filename' => '',
			));
			$onchange = "egw_openWindowCentered('$link'+encodeURIComponent(this.value), '_blank', 1000, 500); this.value=''";
		}
		else	// allow to force users to NOT be able to delete their profiles
		{
			$profiles = $logs = array('never' => lang('Never'));
		}
		if ($GLOBALS['type'] === 'forced' || $GLOBALS['type'] === 'user' &&
			($GLOBALS['egw_info']['user']['preferences']['activesync']['show-log'] !== 'never' ||
			$GLOBALS['egw_info']['user']['preferences']['activesync']['delete-profile'] !== 'never'))
		{
			$settings[] = array(
				'type'  => 'section',
				'title' => 'Maintenance',
			);
			if ($GLOBALS['type'] === 'forced' || $GLOBALS['type'] === 'user' &&
				$GLOBALS['egw_info']['user']['preferences']['activesync']['show-log'] !== 'never')
			{
				$settings['show-log'] = array(
					'type'   => 'select',
					'label'  => 'Show log of following device',
					'name'   => 'show-log',
					'help'   => 'Shows log of a device, enabling it if currently disabled. To disable logging, delete the log file in popup!',
					'values' => $logs,
					'xmlrpc' => True,
					'admin'  => False,
					'onchange' => $onchange,
				);
			}
			if ($GLOBALS['type'] === 'forced' || $GLOBALS['type'] === 'user' &&
				$GLOBALS['egw_info']['user']['preferences']['activesync']['delete-profile'] !== 'never')
			{
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
		}
		return $settings;
	}

	/**
	 * Open log window for log-file specified in GET parameter filename (relative to files_dir)
	 *
	 * $_GET['filename'] has to be in activsync subdir of files dir
	 *
	 * @throws egw_exception_wrong_parameter
	 */
	public function log()
	{
		$filename = $_GET['filename'];
		$profile_dir = $GLOBALS['egw_info']['server']['files_dir'].'/activesync/'.dirname($filename);
		if (!file_exists($profile_dir.'/device_info_'.$GLOBALS['egw_info']['user']['account_lid']) &&
			!($GLOBALS['egw_info']['user']['apps']['admin'] && $filename == 'debug.txt'))
		{
			throw new egw_exception_wrong_parameter("Access denied to file '$filename'!");
		}
		self::debug_log(substr($filename,10));	// strip
	}

	/**
	 * Enable and view debug log
	 *
	 * @param string $filename relativ to activsync subdir of files-dir
	 * @throws egw_exception_wrong_parameter
	 */
	public static function debug_log($filename)
	{
		$profile_dir = $GLOBALS['egw_info']['server']['files_dir'].'/activesync/'.dirname($filename);
		if (!file_exists($profile_dir) || !is_dir($profile_dir) || strpos($filename, '..') !== false ||
			basename($filename) != 'debug.txt')
		{
			throw new egw_exception_wrong_parameter("Access denied to file '$filename'!");
		}
		$GLOBALS['egw_info']['flags']['css'] = '
body { background-color: #e0e0e0; }
pre.tail { background-color: white; padding-left: 5px; margin-left: 5px; }
';
		if (!file_exists($debug_file=$GLOBALS['egw_info']['server']['files_dir'].'/activsync/'.$filename))
		{
			touch($debug_file);
		}
		elseif (is_link($debug_file))
		{
			unlink($debug_file);
			touch($debug_file);
		}
		$tail = new egw_tail('activesync/'.$filename);
		$GLOBALS['egw']->framework->render($tail->show(),false,false);
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
			file_exists($profil=$GLOBALS['egw_info']['server']['files_dir'].'/activesync/'.$hook_data['prefs']['delete-profile']) &&
			file_exists($profil.'/device_info_'.$GLOBALS['egw_info']['user']['account_lid']))
		{
			return self::rm_recursive($profil) ? lang ('Profil %1 deleted.',$hook_data['prefs']['delete-profile']) :
				lang('Deleting of profil %1 failed!',$hook_data['prefs']['delete-profile']);
		}
		// call verification hook of eSync backends
		set_include_path(EGW_SERVER_ROOT.'/activesync' . PATH_SEPARATOR . get_include_path());
		require_once EGW_INCLUDE_ROOT.'/activesync/backend/egw.php';
		$backend = new BackendEGW();

		return implode("<br/>\n", $backend->verify_settings($hook_data));
	}

	/**
	 * Recursivly remove a whole directory (or file)
	 *
	 * @param string $path
	 * @return boolean true on success, false on failure
	 */
	public static function rm_recursive($path)
	{
		$ok = true;
		if (is_dir($path))
		{
			foreach(scandir($path) as $file)
			{
				if ($file != '.' && $file != '..' && !($ok = self::rm_recursive($path.'/'.$file))) break;
			}
			if ($ok) $ok = rmdir($path);
		}
		else
		{
			$ok = unlink($path);
		}
		return $ok;
	}
}

// to not fail, if any backend calls z-push debugLog, simply ignore it
if (!function_exists('debugLog'))
{
	function debugLog($message)
	{

	}
}