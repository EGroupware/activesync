<?php
/**
 * EGroupware: eSync - ActiveSync statemachine based on ZPush2 filestatestatemachine
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package esync
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

include_once('lib/default/filestatemachine.php');

/**
 * ActiveSync statemachine based on ZPush2 filestatestatemachine
 */
class activesync_statemachine extends FileStateMachine
{
	/**
	 * Reference to our backend
	 *
	 * @var BackendEGW
	 */
	protected $backend;

	/**
	 * Constructor
	 *
	 * @param BackendEGW $backend
	 */
	function __construct(BackendEGW $backend)
	{
		$this->backend = $backend;

		parent::FileStateMachine();
	}

	/**
	 * Gets a state for a specified key and counter.
	 * This method sould call IStateMachine->CleanStates()
	 * to remove older states (same key, previous counters)
	 *
	 * @param string    $devid              the device id
	 * @param string    $type               the state type
	 * @param string    $key                (opt)
	 * @param string    $counter            (opt)
	 * @param string    $cleanstates        (opt)
	 *
	 * @access public
	 * @return StateObject
	 * @throws StateNotFoundException, StateInvalidException
	 */
	public function GetState($devid, $type, $key = false, $counter = false, $cleanstates = true)
	{
		$state = parent::GetState($devid, $type, $key, $counter, $cleanstates);
		//ZLog::Write(LOGLEVEL_DEBUG, __METHOD__."('$devid', '$type', '$key', '$counter', $cleanstates) returning state=".array2string($state));
		switch($type)
		{
			case 'devicedata':
				$this->backend->run_on_all_plugins(__FUNCTION__, array(), $state, $devid, $type, $key, $counter, $cleanstates);
		}
		return $state;
	}

	/**
	 * Writes ta state to for a key and counter
	 *
	 * @param StateObject $state
	 * @param string    $devid              the device id
	 * @param string    $type               the state type
	 * @param string    $key                (opt)
	 * @param int       $counter            (opt)
	 *
	 * @access public
	 * @return boolean
	 * @throws StateInvalidException
	 */
	public function SetState($state, $devid, $type, $key = false, $counter = false)
	{
		switch($type)
		{
			case 'devicedata':
				$this->backend->run_on_all_plugins(__FUNCTION__, array(), $state, $devid, $type, $key, $counter);
		}
		//ZLog::Write(LOGLEVEL_DEBUG, __METHOD__."(".array2string($state).", '$devid', '$type', '$key', '$counter')");
		return parent::SetState($state, $devid, $type, $key, $counter);
	}

	/**
	 * Get directory for given device
	 *
	 * Can NOT use FileStateMachine::getDirectoryForDevice($devid, true) as it's private
	 *
	 * @param type $devid
	 * @return type
	 */
	public function getDeviceDirectory($devid)
	{
		$firstLevel = substr(strtolower($devid), -1, 1);
		$secondLevel = substr(strtolower($devid), -2, 1);

		return $GLOBALS['egw_info']['server']['files_dir'] . '/activesync/' . $firstLevel . "/" . $secondLevel;
	}

	/**
	 * Get creation time of device-data
	 *
	 * @param string $devid
	 * @return int
	 */
	public function DeviceDataTime($devid)
	{
		$dir = $this->getDeviceDirectory($devid);

		return filectime($dir.'/'.$devid.'-devicedata');
	}

	public function DeleteState($devid)
	{
		if (!preg_match('/^[a-z0-9]+$/', $devid))
		{
			throw new egw_exception_wrong_parameter("Invalid device-ID '$devid'!");
		}
		foreach(glob($this->getDeviceDirectory($devid).'/'.$devid.'-*') as $file)
		{
			unlinke($file);
		}
	}
}
