<?php
/**
 * EGroupware: eSync - ActiveSync protocol based on Z-Push: hooks: eg. Api\Preferences
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package esync
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Egw;

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
				'Preferences'     => Egw::link('/index.php','menuaction=preferences.uisettings.index&appname='.$appname),
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
	 * @staticvar activesync_backend $backend
	 * @return activesync_backend
	 */
	static function backend()
	{
		static $backend=null;
		if (!isset($backend))
		{
			require_once(EGW_SERVER_ROOT.'/activesync/vendor/z-push/z-push/src/vendor/autoload.php');
			// some files, eg. sqlstatemaschine, does includes it's config relative to z-push sources
			ini_set('include_path', EGW_SERVER_ROOT.'/activesync/vendor/z-push/z-push/src'.PATH_SEPARATOR.ini_get('include_path'));
			include(EGW_SERVER_ROOT.'/activesync/inc/config.php');
			$backend = new activesync_backend();
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
		if (!Api\Hooks::exists('verify_settings', 'activesync'))
		{
			Api\Hooks::read(true);
		}
		if ($GLOBALS['type'] === 'user')
		{
			$enable = array(
				'' => lang('Off'),
				'user' => lang('All your devices'),
			);
			$link = Egw::link('/index.php',array(
				'menuaction' => 'activesync.activesync_hooks.log',
				'filename' => '',
			));
			$onchange = "if (this.value.substr(-4) == '.log') egw_openWindowCentered('$link'+encodeURIComponent(this.value), '_blank', 1000, 500); this.value='';";
			$profiles = array();
			$statemachine = $backend->GetStateMachine();
			$account_lid = Api\Accounts::id2name($hook_data['account_id']);
			foreach($statemachine->GetAllDevices($account_lid) as $devid)
			{
				$devices = $statemachine->GetState($devid, 'devicedata')->devices;
				$device = $devices[$account_lid];
				$enable[$devid.'.log'] = $logs[$devid.'.log'] = $profiles[$devid] = $device->UserAgent.': '.Api\DateTime::to($statemachine->DeviceDataTime($devid)).' ('.$devid.')';
			}
			if ($GLOBALS['egw_info']['user']['apps']['admin'])
			{
				$logs['z-push.log'] = lang('View global z-push log').' ('.lang('admin only').')';
				$logs['z-push-error.log'] = lang('View glogal z-push error log').' ('.lang('admin only').')';
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
					'label'  => 'Enable logging',
					'name'   => 'logging',
					'help'   => 'Enable logging for all your devices or a specific one.',
					'values' => $enable,
					'xmlrpc' => True,
					'admin'  => False,
				);
				$settings['show-log'] = array(
					'type'   => 'select',
					'label'  => 'Show log of following device',
					'name'   => 'show-log',
					'help'   => 'Shows device specific logs. For admins global z-push logs can be viewed too.',
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
					'label'  => lang('Select serverside profile to delete').'<br>'.lang('ALWAYS delete account on device first!'),
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
	 * @throws Api\Exception\WrongParameter
	 */
	public function log()
	{
		$filename = basename($_GET['filename']);
		if (!($GLOBALS['egw_info']['user']['apps']['admin'] ||
			in_array(basename($filename, '.log'),
				self::backend()->GetStateMachine()->GetAllDevices($GLOBALS['egw_info']['user']['account_lid']))))
		{
			throw new Api\Exception\WrongParameter("Access denied to file '$filename'!");
		}
		self::debug_log($filename);
	}

	/**
	 * Enable and view debug log
	 *
	 * @param string $filename relativ to activesync subdir of files-dir
	 * @throws Api\Exception\WrongParameter
	 */
	public static function debug_log($filename)
	{
		// ZLog replaces all non-alphanumerical chars to understore
		$filename = preg_replace('/[^a-z0-9-]/', '_', strtolower(basename($filename, '.log'))).'.log';
		$debug_file = $GLOBALS['egw_info']['server']['files_dir'].'/activesync/'.$filename;

		$GLOBALS['egw_info']['flags']['css'] = '
body { background-color: #e0e0e0; overflow: hidden; }
pre.tail { background-color: white; padding-left: 5px; margin-left: 5px; }
';
		if (!file_exists($debug_file))
		{
			touch($debug_file);
		}
		$tail = new Api\Json\Tail('activesync/'.$filename);
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
			$dev_id = $hook_data['prefs']['delete-profile'];
			$hook_data['prefs']['delete-profile'] = '';
			try {
				$statemachine->DeleteDevice($dev_id);
				return lang('Device %1 deleted.', $dev_id);
			}
			catch(Exception $e) {
				return lang('Deleting of profil %1 failed!', $dev_id)."\n".$e->getMessage();
			}
		}
		// call verification hook of eSync backends
		return implode("<br/>\n", self::backend()->verify_settings($hook_data));
	}
}

// to not fail, if any backend calls z-push ZLog::Write, simply ignore it
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
