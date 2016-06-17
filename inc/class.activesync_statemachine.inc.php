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

use EGroupware\Api;

/**
 * ActiveSync statemachine based on ZPush2 filestatestatemachine
 */
class activesync_statemachine extends SqlStateMachine
{
	/**
	 * Reference to our backend
	 *
	 * @var activesync_backend
	 */
	protected $backend;

	/**
	 * Name of tables, overwritten to add "egw_zpush_" prefix
	 *
	 * @var type
	 */
	protected $settings_table = 'egw_zpush_settings';
	protected $users_table = 'egw_zpush_users';
	protected $states_table = 'egw_zpush_states';

	/**
	 * Constructor
	 *
	 * @param activesync_backend $backend
	 */
	function __construct(activesync_backend $backend)
	{
		$this->backend = $backend;

		parent::__construct();
	}

	/**
	 * Returns an existing PDO instance or creates new if necessary.
	 *
	 * @param boolean $throwFatalException   if true (default) a FatalException is thrown in case of a PDOException, if false the PDOException.
	 *
	 * @access public
	 * @return PDO
	 * @throws FatalException
	 */
	public function getDbh($throwFatalException = true)
	{
		if (!isset($this->dbh))
		{
			try {
				$this->dbh = Api\Db\Pdo::connection();
			}
			// various Db\Exception are thrown, if our regular db-connection fails, which we connect before PDO
			catch(Api\Db\Exception $ex) {
				if ($throwFatalException)
				{
					throw new FatalException(sprintf("SqlStateMachine()->getDbh(): not possible to connect to the state database: %s", $ex->getMessage()));
				}
				else
				{
					throw new PDOException($ex->getMessage(), $ex->getCode(), $ex);
				}
			}
			catch(PDOException $ex) {
				if ($throwFatalException)
				{
					throw new FatalException(sprintf("SqlStateMachine()->getDbh(): not possible to connect to the state database: %s", $ex->getMessage()));
				}
				else
				{
					throw $ex;
				}
			}
		}
		return $this->dbh;
	}

	/**
	 * Check if the database and necessary tables exist.
	 *
	 * @access private
	 * @return boolean
	 * @throws UnavailableException
	 */
	protected function checkDbAndTables()
	{
		if (!isset($GLOBALS['egw_info']['apps']))
		{
			$GLOBALS['egw']->applications->read_installed_apps();
		}
		if (version_compare($GLOBALS['egw_info']['apps']['activesync']['version'] , '16.1.001', '<'))
		{
			throw new UnavailableException('ZPush tables not yet installed, run setup!');
		}
		return true;
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
	public static function getDeviceDirectory($devid)
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
		$sql = "SELECT MAX(updated_at) FROM $this->states_table WHERE device_id = :device_id";
		$params = array(":device_id" => $devid);

		$sth = $this->getDbh()->prepare($sql);
		$sth->execute($params);

		return Api\DateTime::to($sth->fetchColumn(), 'ts');
	}

	/**
	 * Delete state of a given device
	 *
	 * @param string $devid
	 * @throws Api\Exception\WrongParameter
	 */
	public function DeleteDevice($devid)
	{
		$sth = $this->getDbh()->prepare("DELETE FROM $this->states_table WHERE device_id = :device_id");
		$sth->execute(array(":device_id" => $devid));

		$sth2 = $this->getDbh()->prepare("DELETE FROM $this->users_table WHERE device_id = :device_id");
		$sth2->execute(array(":device_id" => $devid));
	}
}
