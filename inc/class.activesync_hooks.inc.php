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
	 * Instanciate EGroupware backend
	 *
	 * @staticvar BackendEGW $backend
	 * @return BackendEGW
	 */
	static function backend()
	{
		static $backend=null;
		if (!isset($backend))
		{
			set_include_path(EGW_SERVER_ROOT.'/activesync/vendor/z-push/z-push/src' . PATH_SEPARATOR . get_include_path());
			include(EGW_SERVER_ROOT.'/activesync/inc/config.php');
			require_once __DIR__.'/class.BackendEGW.inc.php';
			$backend = new BackendEGW();
		}
		return $backend;
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
		$backend = self::backend();
		$last_app = '';
		foreach($backend->egw_settings($hook_data) as $name => $data)
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
			$setup_info = array('activesync' => array());
			if(@file_exists($f)) include($f);
			$hooks->register_hooks('activesync', $setup_info['activesync']['hooks']);
			if (method_exists($GLOBALS['egw'], 'invalidate_session_cache'))
			{
				$GLOBALS['egw']->invalidate_session_cache();
			}
		}
		if ($GLOBALS['type'] === 'user')
		{
			$logs = array(
				'off' => lang('No user-specific logging'),
				'user' => lang('User-specific logging enabled'),
				$GLOBALS['egw_info']['user']['account_lid'].'.log' => lang('View and enable user-specific log'),
			);
			if ($GLOBALS['egw_info']['user']['apps']['admin'])
			{
				$logs['z-push.log'] = lang('View global z-push log').' ('.lang('admin only').')';
				$logs['z-push-error.log'] = lang('View glogal z-push error log').' ('.lang('admin only').')';
			}
			$link = egw::link('/index.php',array(
				'menuaction' => 'activesync.activesync_hooks.log',
				'filename' => '',
			));
			$onchange = "if (this.value.substr(-4) == '.log') egw_openWindowCentered('$link'+encodeURIComponent(this.value), '_blank', 1000, 500)";
			$profiles = array();
			$statemachine = $backend->GetStateMachine();
			foreach($statemachine->GetAllDevices($GLOBALS['egw_info']['user']['account_lid']) as $devid)
			{

				$profiles[$devid] = $devid.' ('.egw_time::to($statemachine->DeviceDataTime($devid)).')';
			}
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
				$settings['logging'] = array(
					'type'   => 'select',
					'label'  => 'Enable or show logs',
					'name'   => 'logging',
					'help'   => 'Shows and enables user-specific logs. For admins global z-push logs can be viewed too.',
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
	 * $_GET['filename'] has to be in activesync subdir of files dir
	 *
	 * @throws egw_exception_wrong_parameter
	 */
	public function log()
	{
		$filename = basename($_GET['filename']);
		if (!($filename == $GLOBALS['egw_info']['user']['account_lid'].'.log' ||
			$GLOBALS['egw_info']['user']['apps']['admin'] && in_array($filename, array('z-push.log', 'z-push-error.log'))))
		{
			throw new egw_exception_wrong_parameter("Access denied to file '$filename'!");
		}
		self::debug_log($filename);
	}

	/**
	 * Enable and view debug log
	 *
	 * @param string $filename relativ to activesync subdir of files-dir
	 * @throws egw_exception_wrong_parameter
	 */
	public static function debug_log($filename)
	{
		// ZLog replaces all non-alphanumerical chars to understore
		$filename = preg_replace('/[^a-z0-9]/', '_', strtolower(basename($filename, '.log'))).'.log';
		$debug_file = $GLOBALS['egw_info']['server']['files_dir'].'/activesync/'.$filename;

		$GLOBALS['egw_info']['flags']['css'] = '
body { background-color: #e0e0e0; overflow: hidden; }
pre.tail { background-color: white; padding-left: 5px; margin-left: 5px; }
';
		if (!file_exists($debug_file))
		{
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
		switch ($hook_data['prefs']['logging'])
		{
			case 'z-push.log':
			case 'z-push-error.log':
				$hook_data['prefs']['logging'] = $GLOBALS['egw_info']['user']['preferences']['activesync']['logging'];
				break;
			default:
				if ($hook_data['prefs']['logging'] == $GLOBALS['egw_info']['user']['account_lid'].'.log')
				{
					$hook_data['prefs']['logging'] = 'user';
				}
				break;
		}
		if ($hook_data['prefs']['delete-profile'] && $hook_data['prefs']['delete-profile'] !== 'never' &&
			($statemachine = self::backend()->GetStateMachine()) &&
			in_array($hook_data['prefs']['delete-profile'], $statemachine->GetAllDevices($GLOBALS['egw_info']['user']['account_lid'])))
		{
			$statemachine->CleanStates($hook_data['prefs']['delete-profile'], '', false);
		}
		// call verification hook of eSync backends
		return implode("<br/>\n", self::backend()->verify_settings($hook_data));
	}
}

// to not fail, if any backend calls z-push debugLog, simply ignore it
if (!class_exists('ZLog'))
{
	class ZLog
	{
		static public function Write($level, $msg, $truncate = false)
		{
			unset($level, $msg, $truncate);
		}
	}
}
if (!function_exists('debugLog'))
{
	function debugLog($message)
	{
		ZLog::Write(LOGLEVEL_DEBUG, $message);
	}
}
