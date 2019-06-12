<?php
/**
 * EGroupware - eSync - ActiveSync protocol based on Z-Push: backend for EGroupware
 *
 * @link http://www.egroupware.org
 * @package esync
 * @author Ralf Becker <rb@egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

/**
 * Z-Push logging backend for EGroupware
 *
 * Needed to fix Z-PushZP-1506 LOGLEVEL_WBXML does not work with zLog::SpecialLogUser()
 */
class activesync_logger extends FileLog
{
    /**
     * If called, the current user should get an extra log-file.
     *
     * If called until the user is authenticated (e.g. at the end of IBackend->Logon()) all
     * messages logged until then will also be logged in the user file.
     *
     * @access public
     * @return void
     */
    public function SpecialLogUser()
	{
        if (!in_array($this->user, $this->specialLogUsers))
		{
			$this->specialLogUsers[] = $this->user;
		}

		parent::SpecialLogUser();
    }
}
