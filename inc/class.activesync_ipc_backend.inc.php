<?php
/**
 * EGroupware: eSync - ActiveSync IPC based on ZPush2 IpcBackend but using EGroupware cache
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package esync
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

use EGroupware\Api;

/**
 * ActiveSync IPC based on ZPush2 IpcBackend but using EGroupware cache instead of PHP shm extension
 */
class activesync_ipc_backend implements IIpcProvider
{
	protected $type;
    private $typeMutex;
	protected $level;

    /**
     * Constructor
     *
	 * @param int $type
	 * @param int $allocate
	 * @param string $class
	 */
    public function __construct($type, $allocate, $class) {
		unset($allocate);	// not used, but required by function signature
		$this->type = $type;
        $this->typeMutex = $type . "MX";
		$this->level = $class == 'TopCollector' ? Api\Cache::TREE : Api\Cache::INSTANCE;
	}

    /**
     * Reinitializes the IPC data. If the provider has no way of performing
     * this action, it should return 'false'.
     *
     * @access public
     * @return boolean
     */
    public function ReInitIPC() {
        return false;
    }

    /**
     * Cleans up the shared memory block
     *
     * @access public
     * @return boolean
     */
    public function Clean() {
        return false;
    }

    /**
     * Indicates if the shared memory is active
     *
     * @access public
     * @return boolean
     */
    public function IsActive() {
        return true;
    }

	/**
	 * How long to wait, before trying again to aquire mutext (in millonth of sec)
	 */
	const BLOCK_USLEEP=20000;

    /**
     * Blocks the class mutex
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
	 *
	 * We try to add mutex to our cache, until we succseed.
	 * It will fail as long other client has stored it
     *
     * @access protected
     * @return boolean
     */
    public function BlockMutex() {
		$n = 0;
		while(!Api\Cache::addCache($this->level, __CLASS__, $this->typeMutex, true, 10))
		{
			if ($n++ > 3) error_log(__METHOD__."() waiting to aquire mutex (this->type=$this->type)");
			usleep(self::BLOCK_USLEEP);	// wait 20ms before retrying
		}
		if ($n) error_log(__METHOD__."() mutex aquired after waiting for ".($n*self::BLOCK_USLEEP/1000)."ms (this->type=$this->type)");
        return true;
    }

    /**
     * Releases the class mutex
     * After the release other processes are able to block the mutex themselfs
     *
     * @access protected
     * @return boolean
     */
    public function ReleaseMutex() {
		//error_log(__METHOD__."() this->type=$this->type");
		return Api\Cache::unsetCache($this->level, __CLASS__, $this->typeMutex);
    }

    /**
     * Indicates if the requested variable is available in shared memory
     *
     * @param int   $id     int indicating the variable
     *
     * @access protected
     * @return boolean
     */
    public function HasData($id = 2) {
		return Api\Cache::getCache($this->level, __CLASS__, $this->type.':'.$id) !== null;
    }

    /**
     * Returns the requested variable from shared memory
     *
     * @param int   $id     int indicating the variable
     *
     * @access protected
     * @return mixed
     */
    public function GetData($id = 2) {
		return Api\Cache::getCache($this->level, __CLASS__, $this->type.':'.$id);
    }

    /**
     * Writes the transmitted variable to shared memory
     * Subclasses may never use an id < 2!
     *
     * @param mixed $data   data which should be saved into shared memory
     * @param int   $id     int indicating the variable (bigger than 2!)
     *
     * @access protected
     * @return boolean
     */
    public function SetData($data, $id = 2) {
		return Api\Cache::setCache($this->level, __CLASS__, $this->type.':'.$id, $data);
    }
}
