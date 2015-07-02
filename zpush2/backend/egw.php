<?php
/**
 * EGroupware - eSync - ActiveSync protocol based on Z-Push: backend for EGroupware
 *
 * @link http://www.egroupware.org
 * @package esync
 * @author Ralf Becker <rb@stylite.de>
 * @author Stylite AG <info@stylite.de>
 * @author Philip Herbert <philip@knauber.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

include_once('lib/interface/ibackend.php');
include_once('lib/interface/ichanges.php');
include_once('lib/interface/iexportchanges.php');
include_once('lib/interface/iimportchanges.php');
include_once('lib/interface/isearchprovider.php');
include_once('lib/interface/istatemachine.php');
include_once('lib/default/diffbackend/diffbackend.php');

/**
 * Z-Push backend for EGroupware
 *
 * Uses EGroupware application specific plugins, eg. mail_zpush class
 *
 * @todo implement GetStateMaschine() to return own IStateMachine to be used instead of FileStateMachine to store states in DB
 * @todo change AlterPingChanges method to GetFolderState in plugins, interfaces and egw backend directly returning state
 */
class BackendEGW extends BackendDiff
{
	var $egw_sessionID;

	var $_user;
	var $_devid;
	var $_protocolversion;

	var $hierarchyimporter;
	var $contentsimporter;
	var $exporter;

	/**
	 * Log into EGroupware
	 *
	 * @param string $username
	 * @param string $domain
	 * @param string $password
	 * @return boolean TRUE if the logon succeeded, FALSE if not
     * @throws FatalException   e.g. some required libraries are unavailable
	 */
	public function Logon($username, $domain, $password)
	{
		// check credentials and create session
   		$GLOBALS['egw_info']['flags']['currentapp'] = 'activesync';
		if (!(($this->egw_sessionID = egw_session::get_sessionid(true)) && $GLOBALS['egw']->session->verify($this->egw_sessionID) &&
				base64_decode(egw_cache::getSession('phpgwapi', 'password')) === $password ||	// check if session contains password
			($this->egw_sessionID = $GLOBALS['egw']->session->create($username,$password,'text',true))))	// true = no real session
		{
			debugLog(__METHOD__."() z-push authentication failed: ".$GLOBALS['egw']->session->cd_reason);
			return false;
		}
		if (!isset($GLOBALS['egw_info']['user']['apps']['activesync']))
		{
			debugLog(__METHOD__."() z-push authentication failed: NO run rights for E-Push application!");
			return false;
		}
   		debugLog(__METHOD__."('$username','$domain',...) logon SUCCESS");

   		// call plugins in case they are interested in being call on each command
   		$this->run_on_all_plugins(__FUNCTION__, array(), $username, $domain, $password);

   		$this->_loggedin = TRUE;

		// check if we support loose provisioning for that device
		$response = $this->run_on_all_plugins('LooseProvisioning', array(), Request::GetDeviceID());
		define('LOOSE_PROVISIONING', $response ? $response['policy_loose'] : false);

		return true;
	}

	/**
	 * Called before closing connection
	 */
	public function Logoff()
	{
		if ($this->mail) $this->mail->closeConnection();
		unset($this->mail);

		$this->_loggedin = FALSE;

		debugLog ("LOGOFF");
	}

	/**
	 *  This function is analogous to GetMessageList.
	 */
	function GetFolderList()
	{
		error_log(__METHOD__."()");
		$folderlist = $this->run_on_all_plugins(__FUNCTION__);
		error_log(__METHOD__."() run_On_all_plugins() returned ".array2string($folderlist));
		$applist = array('addressbook','calendar','mail');
		foreach($applist as $app)
		{
			if (!isset($GLOBALS['egw_info']['user']['apps'][$app]))
			{
				$folderlist[] = $folder = array(
					'id'	=>	$this->createID($app,
						$app == 'mail' ? 0 :	// fmail uses id=0 for INBOX, other apps account_id of user
							$GLOBALS['egw_info']['user']['account_id']),
					'mod'	=>	'not-enabled',
					'parent'=>	'0',
				);
				debugLog(__METHOD__."() adding for disabled $app ".array2string($folder));
			}
		}
		//debugLog(__METHOD__."() returning ".array2string($folderlist));

		return $folderlist;
	}

	/**
	 * Get Information about a folder
	 *
	 * @param string $id
	 * @return SyncFolder|boolean false on error
	 */
	function GetFolder($id)
	{
		if (!($ret = $this->run_on_plugin_by_id(__FUNCTION__, $id)))
		{
			$this->splitID($id, $type, $folder, $app);

			if (!isset($GLOBALS['egw_info']['user']['apps'][$app]))
			{
				$ret = new SyncFolder();
				$ret->serverid = $id;
				$ret->parentid = '0';
				$ret->displayname = 'not-enabled';
				$account_id = $GLOBALS['egw_info']['user']['account_id'];
				switch($app)
				{
					case 'addressbook':
						$ret->type = $folder == $account_id ? SYNC_FOLDER_TYPE_CONTACT : SYNC_FOLDER_TYPE_USER_CONTACT;
						break;
					case 'calendar':
						$ret->type = $folder == $account_id ? SYNC_FOLDER_TYPE_APPOINTMENT : SYNC_FOLDER_TYPE_USER_APPOINTMENT;
						break;
					case 'infolog':
						$ret->type = $folder == $account_id ? SYNC_FOLDER_TYPE_TASK : SYNC_FOLDER_TYPE_USER_TASK;
						break;
					default:
						$ret->type = $folder == 0 ? SYNC_FOLDER_TYPE_INBOX : SYNC_FOLDER_TYPE_USER_MAIL;
						break;
				}
				debugLog(__METHOD__."($id) return ".array2string($ret)." for disabled app!");
			}
		}
		//debugLog(__METHOD__."('$id') returning ".array2string($ret));
		return $ret;
	}

	/**
	 * Return folder stats. This means you must return an associative array with the
	 * following properties:
	 *
	 * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
	 *		 How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
	 * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
	 * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
	 *		  the folder has not changed. In practice this means that 'mod' can be equal to the folder name
	 *		  as this is the only thing that ever changes in folders. (the type is normally constant)
	 *
	 * @return array with values for keys 'id', 'mod' and 'parent'
	 */
	function StatFolder($id)
	{
		if (!($ret = $this->run_on_plugin_by_id(__FUNCTION__, $id)))
		{
			$this->splitID($id, $type, $folder, $app);

			if (!isset($GLOBALS['egw_info']['user']['apps'][$app]))
			{
				$ret = array(
					'id' => $id,
					'mod'	=>	'not-enabled',
					'parent'=>	'0',
				);
				debugLog(__METHOD__."($id) return ".array2string($ret)." for disabled app!");
			}
		}
		//debugLog(__METHOD__."('$id') returning ".array2string($ret));
		return $ret;
	}


	/**
	 * Creates or modifies a folder
	 *
	 * Attention: eGroupware currently does not support creating folders. The first device seen during testing
	 * is now iOS 5 (beta). At least returning false causes the sync not to break.
	 * As we currently do not support this function currently nothing is forwarded to the plugin.
	 *
	 * @param $id of the parent folder
	 * @param $oldid => if empty -> new folder created, else folder is to be renamed
	 * @param $displayname => new folder name (to be created, or to be renamed to)
	 * @param type => folder type, ignored in IMAP
	 *
	 * @return stat | boolean false on error
	 *
	 */
	function ChangeFolder($id, $oldid, $displayname, $type)
	{
		debugLog(__METHOD__."(id=$id, oldid=$oldid, displaname=$displayname, type=$type)");
		debugLog(__METHOD__." WARNING : we currently do not support creating folders, now informing the device that this has failed");
		return (false);
	}


	/**
	 * Should return a list (array) of messages, each entry being an associative array
	 * with the same entries as StatMessage(). This function should return stable information; ie
	 * if nothing has changed, the items in the array must be exactly the same. The order of
	 * the items within the array is not important though.
	 *
	 * The cutoffdate is a date in the past, representing the date since which items should be shown.
	 * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
	 * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
	 * will work OK apart from that.
	 *
	 * @param string $id folder id
	 * @param int $cutoffdate=null
	 * @return array
  	 */
	function GetMessageList($id, $cutoffdate=NULL)
	{
		$this->splitID($id, $type, $folder, $app);
		debugLog(__METHOD__."($id, $cutoffdate) type=$type, folder=$folder, app=$app");
		if (!($ret = $this->run_on_plugin_by_id(__FUNCTION__, $id, $cutoffdate)))
		{
			if (!isset($GLOBALS['egw_info']['user']['apps'][$app]))
			{
				debugLog(__METHOD__."($id, $cutoffdate) return array() for disabled app!");
				$ret = array();
			}
		}
		// allow other apps to insert meeting requests
		/*if ($app == 'mail' && $folder == 0)
		{
			$before = count($ret);
			$not_uids = array();
			foreach($ret as $message)
			{
				if (isset($message->meetingrequest) && is_a($message->meetingrequest, 'SyncMeetingRequest'))
				{
					$not_uids[] = self::globalObjId2uid($message->meetingrequest->globalobjid);
				}
			}
			$ret2 = $this->run_on_all_plugins('GetMeetingRequests', $ret, $not_uids, $cutoffdate);
			if (is_array($ret2) && !empty($ret2))
			{
				debugLog(__METHOD__."($id, $cutoffdate) call to GetMeetingRequests added ".(count($ret2)-$before)." messages");
				debugLog(array2string($ret2));
				$ret = $ret2; // should be already merged by run_on_all_plugins
			}
		}*/
		debugLog(__METHOD__.'->retrieved '.count($ret)." Messages for type=$type, folder=$folder, app=$app ($id, $cutoffdate):".array2string($ret));
		return $ret;
	}

	/**
	 * convert UID to GlobalObjID for meeting requests
	 *
	 * @param string $uid iCal UID
	 * @return binary GlobalObjId
	 */
	public static function uid2globalObjId($uid)
	{
		$objid = base64_encode(
			/* Bytes 1-16: */	"\x04\x00\x00\x00\x82\x00\xE0\x00\x74\xC5\xB7\x10\x1A\x82\xE0\x08".
			/* Bytes 17-20: */	"\x00\x00\x00\x00".
			/* Bytes 21-36: */	"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00".
			/* Bytes 37-­40: */	pack('V',13+bytes($uid)).	// binary length + 13 for next line and terminating \x00
			/* Bytes 41-­52: */	'vCal-Uid'."\x01\x00\x00\x00".
			$uid."\x00");
		debugLog(__METHOD__."('$uid') returning '$objid'");
		return $objid;
	}

	/**
	 * Extract UID from GlobalObjId
	 *
	 * @param string $objid
	 * @return string
	 */
	public static function globalObjId2uid($objid)
	{
		$uid = cut_bytes(base64_decode($objid), 52, -1);	// -1 to cut off terminating \0x00
		debugLog(__METHOD__."('$objid') returning '$uid'");
		return $uid;
	}

	/**
	 * Get specified item from specified folder.
	 *
	 * @param string $folderid
	 * @param string $id
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
	 * @return $messageobject|boolean false on error
	 */
	function GetMessage($folderid, $id, $contentparameters)
	{
		debugLog(__METHOD__."($folderid, $id)");
		/*if ($id < 0)
		{
			$this->splitID($folderid, $type, $folder, $app);
			if ($app == 'mail' && $folder == 0)
			{

				return $this->run_on_all_plugins('GetMeetingRequest', 'return-first', $id, $contentparameters);
			}
		}*/
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $contentparameters);
	}

	/**
	 * GetAttachmentData - may be MailSpecific
	 * Should return attachment data for the specified attachment. The passed attachment identifier is
	 * the exact string that is returned in the 'AttName' property of an SyncAttachment. So, you should
	 * encode any information you need to find the attachment in that 'attname' property.
	 *
	 * @param string $attname - should contain (folder)id
	 * @return true, prints the content of the attachment
	 */
	function GetAttachmentData($attname)
	{
		list($id, $uid, $part) = explode(":", $attname); // split name as it is constructed that way FOLDERID:UID:PARTID
		return $this->run_on_plugin_by_id(__FUNCTION__, $id, $attname);
	}

	/**
	 * ItemOperationsGetAttachmentData - may be MailSpecific
	 * Should return attachment data for the specified attachment. The passed attachment identifier is
	 * the exact string that is returned in the 'AttName' property of an SyncAttachment. So, you should
	 * encode any information you need to find the attachment in that 'attname' property.
	 *
	 * @param string $attname - should contain (folder)id
	 * @return SyncAirSyncBaseFileAttachment-object
	 */
	function ItemOperationsGetAttachmentData($attname)
	{
		list($id, $uid, $part) = explode(":", $attname); // split name as it is constructed that way FOLDERID:UID:PARTID
		return $this->run_on_plugin_by_id(__FUNCTION__, $id, $attname);
	}

	function ItemOperationsFetchMailbox($entryid, $bodypreference, $mimesupport = 0) {
		debugLog(__METHOD__.__LINE__.'Entry:'.$entryid.', BodyPref:'.array2string( $bodypreference).', MimeSupport:'.array2string($mimesupport));
		list($folderid, $uid) = explode(":", $entryid); // split name as it is constructed that way FOLDERID:UID:PARTID
		$this->splitID($folderid, $type, $folder, $app);
		//debugLog(__METHOD__.__LINE__."$folderid, $type, $folder, $app");
		if ($app == 'mail')
		{									// GetMessage($folderid, $id, $truncsize, $bodypreference=false, $optionbodypreference=false, $mimesupport = 0)
			return $this->run_on_plugin_by_id('GetMessage', $folderid, $uid, $truncsize=($bodypreferences[1]['TruncationSize']?$bodypreferences[1]['TruncationSize']:500), $bodypreference, $optionbodypreference=false, $mimesupport);
		}
		else
		{
			return false;
		}
	}

	/**
	 * StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
	 * 'id'     => Server unique identifier for the message. Again, try to keep this short (under 20 chars)
	 * 'flags'  => simply '0' for unread, '1' for read
	 * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
	 *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
	 *             time for this field, which will change as soon as the contents have changed.
	 *
	 * @param string $folderid
	 * @param int integer id of message
	 * @return array
	 */
	function StatMessage($folderid, $id)
	{
		if ($id < 0)
		{
			$this->splitID($folderid, $type, $folder, $app);
			if (($app == 'mail') && $folder == 0)
			{
				return $this->run_on_all_plugins('StatMeetingRequest',array(),$id);
			}
		}
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id);
	}

	/**
	 * Indicates if the backend has a ChangesSink.
	 * A sink is an active notification mechanism which does not need polling.
	 * The EGroupware backend simulates a sink by polling status information of the folder
	 *
	 * @access public
	 * @return boolean
	 */
	public function HasChangesSink()
	{
		$this->sinkfolders = array();
		$this->sinkstates = array();
		return true;
	}

	/**
	 * The folder should be considered by the sink.
	 * Folders which were not initialized should not result in a notification
	 * of IBacken->ChangesSink().
	 *
	 * @param string        $folderid
	 *
	 * @access public
	 * @return boolean      false if found can not be found
	 */
	public function ChangesSinkInitialize($folderid)
	{
		ZLog::Write(LOGLEVEL_DEBUG, __METHOD__."($folderid)");

		$this->sinkfolders[] = $folderid;

		return true;
	}

	/**
	 * The actual ChangesSink.
	 * For max. the $timeout value this method should block and if no changes
	 * are available return an empty array.
	 * If changes are available a list of folderids is expected.
	 *
	 * @param int           $timeout        max. amount of seconds to block
	 *
	 * @access public
	 * @return array
	 */
	public function ChangesSink($timeout = 30)
	{
		ZLog::Write(LOGLEVEL_DEBUG, __METHOD__."($timeout)");
		$notifications = array();
		$stopat = time() + $timeout - 1;

		while($stopat > time() && empty($notifications))
		{
			foreach ($this->sinkfolders as $folderid)
			{
				$newstate = null;
				$this->AlterPingChanges($folderid, $newstate);

				if (!isset($this->sinkstates[$folderid]))
					$this->sinkstates[$folderid] = $newstate;

				if ($this->sinkstates[$folderid] != $newstate)
				{
					$notifications[] = $folderid;
					$this->sinkstates[$folderid] = $newstate;
				}
			}

			if (empty($notifications))
			{
				$sleep_time = min($timeout, 30);
				ZLog::Write(LOGLEVEL_DEBUG, __METHOD__."($timeout) no changes, going to sleep($sleep_time)");
				sleep($sleep_time);
			}
		}
		ZLog::Write(LOGLEVEL_DEBUG, __METHOD__."($timeout) returning ".array2string($notifications));

		return $notifications;
	}

	/**
	 * Return a changes array
	 *
	 * if changes occurr default diff engine computes the actual changes
	 *
	 * We can NOT use run_on_plugin_by_id, because $syncstate is passed by reference!
	 *
	 * @param string $folderid
	 * @param string &$syncstate on call old syncstate, on return new syncstate
	 * @return array|boolean false if $folderid not found, array() if no changes or array(array("type" => "fakeChange"))
	 */
	function AlterPingChanges($folderid, &$syncstate)
	{
		$this->setup_plugins();

		$this->splitID($folderid, $type, $folder);

		if (is_numeric($type))
		{
			$type = 'mail';
		}

		$ret = array();		// so unsupported or not enabled/installed backends return "no change"
		if (isset($this->plugins[$type]) && method_exists($this->plugins[$type], __FUNCTION__))
		{
			$ret = call_user_func_array(array($this->plugins[$type], __FUNCTION__), array($folderid, &$syncstate));
		}
		debugLog(__METHOD__."('$folderid','".array2string($syncstate)."') type=$type, folder=$folder returning ".array2string($ret));
		return $ret;
	}

    /**
     * Called when a message has been changed on the mobile. The new message must be saved to disk.
     * The return value must be whatever would be returned from StatMessage() after the message has been saved.
     * This way, the 'flags' and the 'mod' properties of the StatMessage() item may change via ChangeMessage().
     * This method will never be called on E-mail items as it's not 'possible' to change e-mail items. It's only
     * possible to set them as 'read' or 'unread'.
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param SyncXXX             $message             the SyncObject containing a message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return array                        same return value as StatMessage()
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage($folderid, $id, $message, $contentParameters)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $message, $contentParameters);
	}

    /**
     * Called when the user moves an item on the PDA from one folder to another. Whatever is needed
     * to move the message on disk has to be done here. After this call, StatMessage() and GetMessageList()
     * should show the items to have a new parent. This means that it will disappear from GetMessageList()
     * of the sourcefolder and the destination folder will show the new message
     *
     * @param string              $folderid            id of the source folder
     * @param string              $id                  id of the message
     * @param string              $newfolderid         id of the destination folder
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_MOVEITEMSSTATUS_* exceptions
     */
    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $newfolderid, $contentParameters);
	}

    /**
     * Called when the user has requested to delete (really delete) a message. Usually
     * this means just unlinking the file its in or somesuch. After this call has succeeded, a call to
     * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the mobile
     * as it will be seen as a 'new' item. This means that if this method is not implemented, it's possible to
     * delete messages on the PDA, but as soon as a sync is done, the item will be resynched to the mobile
     *
     * @param string              $folderid             id of the folder
     * @param string              $id                   id of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id, $contentParameters)
	{
		if ($id < 0)
		{
			$this->splitID($folderid, $type, $folder, $app);
			if ($app == 'mail' && $folder == 0)
			{
				return $this->run_on_all_plugins('DeleteMeetingRequest', array(), $id, $contentParameters);
			}
		}
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $contentParameters);
	}

    /**
     * Changes the 'read' flag of a message on disk. The $flags
     * parameter can only be '1' (read) or '0' (unread). After a call to
     * SetReadFlag(), GetMessageList() should return the message with the
     * new 'flags' but should not modify the 'mod' parameter. If you do
     * change 'mod', simply setting the message to 'read' on the mobile will trigger
     * a full resync of the item from the server.
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param int                 $flags               read flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag($folderid, $id, $flags, $contentParameters)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $flags, $contentParameters);
	}

	function ChangeMessageFlag($folderid, $id, $flag)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $flag);
	}

	/**
	 * Applies settings to and gets informations from the device
	 *
	 * @param SyncObject    $settings (SyncOOF or SyncUserInformation possible)
	 *
	 * @access public
	 * @return SyncObject   $settings
	 */
	public function Settings($settings)
	{
		parent::Settings($settings);

		if ($settings instanceof SyncUserInformation)
		{
			$settings->emailaddresses[] = $GLOBALS['egw_info']['user']['account_email'];
			$settings->Status = SYNC_SETTINGSSTATUS_SUCCESS;
		}
		if ($settings instanceof SyncOOF)
		{
			$settings->oofstate = 0;	// OOF (out of office) not yet supported via AS
		}

		// call all plugins with settings
		$this->run_on_all_plugins(__FUNCTION__, array(), $settings);

		return $settings;
	}

	/**
	 * Get provisioning data for a given device and user
	 *
	 * @param string $devid
	 * @param string $user
	 * @return array
	 */
	function getProvision($devid, $user)
	{
		$ret = $this->run_on_all_plugins(__FUNCTION__, array(), $devid, $user);
		//error_log(__METHOD__."('$devid', '$user') returning ".array2string($ret));
		return $ret;
	}

	/**
	 * Checks if the sent policykey matches the latest policykey on the server
	 *
	 * Plugins either return array() to NOT change standard response or array('response' => value)
	 *
	 * @param string $policykey
	 * @param string $devid
	 *
	 * @return int status flag SYNC_PROVISION_STATUS_SUCCESS, SYNC_PROVISION_STATUS_POLKEYMISM (triggers provisioning)
	 */
	function CheckPolicy($policykey, $devid)
	{
		$response = array('response' => parent::CheckPolicy($policykey, $devid));

		// allow plugins to overwrite standard responses
		$response = $this->run_on_all_plugins(__FUNCTION__, $response, $policykey, $devid);

		//error_log(__METHOD__."('$policykey', '$devid') returning ".array2string($response['response']));
		return $response['response'];
	}

	/**
	 * Checks if policy of device allows loose provisioning
	 *
	 * Plugins either return array() to NOT change standard response or array('response' => value)
	 *
	 * @param string $policykey
	 * @param string $devid
	 *
	 * @return boolean
	 */
	function LooseProvisioning($devid)
	{
		$response = array('response' => true);	// allow loose provisioning by default

		// allow plugins to overwrite standard responses
		$response = $this->run_on_all_plugins(__FUNCTION__, $response, $devid);

		//error_log(__METHOD__."('$devid') returning ".array2string($response['response']));
		return $response['response'];
	}

	/**
	 * Return a device wipe status
	 *
	 * Z-Push will send remote wipe request to client, if returned status is SYNC_PROVISION_RWSTATUS_PENDING or _WIPED
	 *
	 * Plugins either return array() to NOT change standard response or array('response' => value)
	 *
	 * @param string $user
	 * @param string $pass
	 * @param string $devid
	 * @return int SYNC_PROVISION_RWSTATUS_NA, SYNC_PROVISION_RWSTATUS_OK, SYNC_PROVISION_RWSTATUS_PENDING, SYNC_PROVISION_RWSTATUS_WIPED
	 */
	function getDeviceRWStatus($user, $pass, $devid)
	{
		$response = array('response' => false);
		// allow plugins to overwrite standard responses
		$response = $this->run_on_all_plugins(__FUNCTION__, $response, $user, $pass, $devid);

		//error_log(__METHOD__."('$user', '$pass', '$devid') returning ".array2string($response['response']));
		return $response['response'];
	}

	/**
	 * Set a new rw status for the device
	 *
	 * Z-Push call this with SYNC_PROVISION_RWSTATUS_WIPED, after sending remote wipe command
	 *
	 * Plugins either return array() to NOT change standard response or array('response' => value)
	 *
	 * @param string $user
	 * @param string $pass
	 * @param string $devid
	 * @param int $status SYNC_PROVISION_RWSTATUS_OK, SYNC_PROVISION_RWSTATUS_PENDING, SYNC_PROVISION_RWSTATUS_WIPED
	 *
	 * @return boolean seems not to be used in Z-Push
	 */
	function setDeviceRWStatus($user, $pass, $devid, $status)
	{
		$response = array('response' => false);
		// allow plugins to overwrite standard responses
		$response = $this->run_on_all_plugins(__FUNCTION__, $response, $user, $pass, $devid, $status);

		//error_log(__METHOD__."('$user', '$pass', '$devid', '$status') returning ".array2string($response['response']));
		return $response['response'];
	}

	/**
	 * Sends a message which is passed as rfc822. You basically can do two things
	 * 1) Send the message to an SMTP server as-is
	 * 2) Parse the message yourself, and send it some other way
	 * It is up to you whether you want to put the message in the sent items folder. If you
	 * want it in 'sent items', then the next sync on the 'sent items' folder should return
	 * the new message as any other new message in a folder.
	 *
	 * @param string $rfc822 mail
	 * @param array $smartdata=array() values for keys:
	 * 	'task': 'forward', 'new', 'reply'
	 *  'itemid': id of message if it's an reply or forward
	 *  'folderid': folder
	 *  'replacemime': false = send as is, false = decode and recode for whatever reason ???
	 *	'saveinsentitems': 1 or absent?
	 * @param boolean|double $protocolversion=false
	 * @return boolean true on success, false on error
	 *
	 * @see eg. BackendIMAP::SendMail()
	 * @todo implement either here or in fmail backend
	 * 	(maybe sending here and storing to sent folder in plugin, as sending is supposed to always work in EGroupware)
	 */
	function SendMail($rfc822, $smartdata=array(), $protocolversion = false)
	{
		$ret = $this->run_on_all_plugins(__FUNCTION__, 'return-first', $rfc822, $smartdata, $protocolversion);
		debugLog(__METHOD__."('$rfc822', ".array2string($smartdata).", $protocolversion) returning ".array2string($ret));
		return $ret;
	}

	/**
	 * Returns array of items which contain searched for information
	 *
	 * @param string $searchquery
	 * @param string $searchname
	 *
	 * @return array
	 */
	function getSearchResults($searchquery,$searchname)
	{
		//debugLog("EGW:getSearchResults : query: ". print_r($searchquery,true) . " : searchname : ". $searchname);
		switch (strtoupper($searchname)) {
			case 'GAL':
				$rows = $this->run_on_all_plugins('getSearchResultsGAL',array(),$searchquery);
				break;
			case 'MAILBOX':
				$rows = $this->run_on_all_plugins('getSearchResultsMailbox',array(),$searchquery);
				break;
		  	case 'DOCUMENTLIBRARY':
				$rows = $this->run_on_all_plugins('getSearchDocumentLibrary',array(),$searchquery);
		  		break;
		  	default:
		  		debugLog (__METHOD__." unknown searchname ". $searchname);
		  		return NULL;
		}
		if (is_array($rows))
		{
			$result = array(
				'rows' => &$rows,
				'status' => 1,
				'global_search_status' => 1,
			);
		}
		//error_log(__METHOD__."('$searchquery', '$searchname') returning ".count($result['rows']).' rows = '.array2string($result));
		return $result;
	}

/*
04/28/11 21:55:28 [8923] POST cmd: MeetingResponse
04/28/11 21:55:28 [8923] I  <MeetingResponse:MeetingResponse>
04/28/11 21:55:28 [8923] I   <MeetingResponse:Request>
04/28/11 21:55:28 [8923] I    <MeetingResponse:UserResponse>
04/28/11 21:55:28 [8923] I     1
04/28/11 21:55:28 [8923] I    </MeetingResponse:UserResponse>
04/28/11 21:55:28 [8923] I    <MeetingResponse:FolderId>
04/28/11 21:55:28 [8923] I     101000000000
04/28/11 21:55:28 [8923] I    </MeetingResponse:FolderId>
04/28/11 21:55:28 [8923] I    <MeetingResponse:RequestId>
04/28/11 21:55:28 [8923] I     99723
04/28/11 21:55:28 [8923] I    </MeetingResponse:RequestId>
04/28/11 21:55:28 [8923] I   </MeetingResponse:Request>
04/28/11 21:55:28 [8923] I  </MeetingResponse:MeetingResponse>
04/28/11 21:55:28 [8923] BackendEGW::MeetingResponse('99723', '101000000000', '1', ) returning FALSE
04/28/11 21:55:28 [8923] O  <MeetingResponse:MeetingResponse>
04/28/11 21:55:28 [8923] O   <MeetingResponse:Result>
04/28/11 21:55:28 [8923] O    <MeetingResponse:RequestId>
04/28/11 21:55:28 [8923] O    99723
04/28/11 21:55:28 [8923] O    </MeetingResponse:RequestId>
04/28/11 21:55:28 [8923] O    <MeetingResponse:Status>
04/28/11 21:55:28 [8923] O    2
04/28/11 21:55:28 [8923] O    </MeetingResponse:Status>
04/28/11 21:55:28 [8923] O   </MeetingResponse:Result>
04/28/11 21:55:28 [8923] O  </MeetingResponse:MeetingResponse>
*/
	/**
	 *
	 * @see BackendDiff::MeetingResponse()
	 * @param int $requestid uid of mail with meeting request
	 * @param string $folderid folder of meeting request mail
	 * @param int $response 1=accepted, 2=tentative, 3=decline
	 * @param int &$calendarid on return id of calendar item
	 * @todo handle commented calendarid
	 * @return boolean true on success, false on error
	 */
	function MeetingResponse($requestid, $folderid, $response)//, &$calendarid)
	{
		$calendarid = $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $requestid, $response);

		debugLog(__METHOD__."('$requestid', '$folderid', '$response', $calendarid) returning ".array2string((bool)$calendarid));
		return (bool)$calendarid;
	}

	/**
	 * Type ID for addressbook
	 *
	 * To work around a bug in Android / HTC the ID must not have leading "0" (as they get removed)
	 *
	 * @var int
	 */
	const TYPE_ADDRESSBOOK = 0x1000;
	const TYPE_CALENDAR = 0x1001;
	const TYPE_INFOLOG = 0x1002;
	const TYPE_MAIL = 0x1010;

	/**
	 * Create a max. 32 hex letter ID, current 20 chars are used
	 *
	 * Currently only $folder supports negative numbers correctly on 64bit PHP systems
	 *
	 * @param int|string $type appname or integer mail account id
	 * @param int $folder integer folder ID
	 * @return string
	 * @throws egw_exception_wrong_parameter
	 */
	public function createID($type,$folder)
	{
		// get a nummeric $type
		switch((string)($t=$type))	// we have to cast to string, as (0 == 'addressbook')===TRUE!
		{
			case 'addressbook':
				$type = self::TYPE_ADDRESSBOOK;
				break;
			case 'calendar':
				$type = self::TYPE_CALENDAR;
				break;
			case 'infolog':
				$type = self::TYPE_INFOLOG;
				break;
			case 'mail':
				$type = self::TYPE_MAIL;
				break;
			default:
				if (!is_numeric($type))
				{
					throw new egw_exception_wrong_parameter("type='$type' is NOT nummeric!");
				}
				$type += self::TYPE_MAIL;
				break;
		}

		if (!is_numeric($folder))
		{
			throw new egw_exception_wrong_parameter("folder='$folder' is NOT nummeric!");
		}

		$folder_hex = sprintf('%08X',$folder);
		// truncate negative number on a 64bit system to 8 hex digits = 32bit
		if (strlen($folder_hex) > 8) $folder_hex = substr($folder_hex,-8);
		$str = sprintf('%04X',$type).$folder_hex;

		debugLog(__METHOD__."('$t','$folder') type=$type --> '$str'");

		return $str;
	}

	/**
	 * Split an ID string into $app and $folder
	 *
	 * Currently only $folder supports negative numbers correctly on 64bit PHP systems
	 *
	 * @param string $str
	 * @param string|int &$type on return appname or integer mail account ID
	 * @param int &$folder on return integer folder ID
	 * @param string &$app=null application of ID
	 * @throws egw_exception_wrong_parameter
	 */
	public function splitID($str,&$type,&$folder,&$app=null)
	{
		$type = hexdec(substr($str,0,4));
		$folder = hexdec(substr($str,4,8));
		// convert 32bit negative numbers on a 64bit system to a 64bit negative number
		if ($folder > 0x7fffffff) $folder -= 0x100000000;

		switch($type)
		{
			case self::TYPE_ADDRESSBOOK:
				$app = $type = 'addressbook';
				break;
			case self::TYPE_CALENDAR:
				$app = $type = 'calendar';
				break;
			case self::TYPE_INFOLOG:
				$app = $type = 'infolog';
				break;
			default:
				if ($type < self::TYPE_MAIL)
				{
					throw new egw_exception_wrong_parameter("Unknown type='$type'!");
				}
				$app = 'mail';
				$type -= self::TYPE_MAIL;
				break;
		}
		// debugLog(__METHOD__."('$str','$type','$folder')");
	}

	/**
	 * Convert note to requested bodypreference format and truncate if requested
	 *
	 * @param string $note containing the plaintext message
	 * @param array $bodypreference
	 * @param SyncBaseBody $airsyncbasebody the airsyncbasebody object to send to the client
	 *
	 * @return string plain textbody for message or false
	 */
	public function note2messagenote($note, $bodypreference, SyncBaseBody $airsyncbasebody)
	{
		//error_log (__METHOD__."('$note', ".array2string($bodypreference).", ...)");
		if ($bodypreference == false)
		{
			return ($note);
		}
		else
		{
			if (isset($bodypreference[2]))
			{
				debugLog("HTML Body");
				$airsyncbasebody->type = 2;
				$html = '<html>'.
						'<head>'.
						'<meta name="Generator" content="eGroupware/Z-Push">'.
						'<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.
						'</head>'.
						'<body>'.
						str_replace(array("\n","\r","\r\n"),"<br />",$note).
						'</body>'.
						'</html>';
				if (isset($bodypreference[2]["TruncationSize"]) && strlen($html) > $bodypreference[2]["TruncationSize"])
				{
					$html = utf8_truncate($html,$bodypreference[2]["TruncationSize"]);
					$airsyncbasebody->truncated = 1;
				}
				$airsyncbasebody->estimateddatasize = strlen($html);
				$airsyncbasebody->data = $html;
			}
			else
			{
				debugLog("Plaintext Body");
				$airsyncbasebody->type = 1;
				$plainnote = str_replace("\n","\r\n",str_replace("\r","",$note));
				if(isset($bodypreference[1]["TruncationSize"]) && strlen($plainnote) > $bodypreference[1]["TruncationSize"])
				{
					$plainnote = utf8_truncate($plainnote, $bodypreference[1]["TruncationSize"]);
					$airsyncbasebody->truncated = 1;
				}
				$airsyncbasebody->estimateddatasize = strlen($plainnote);
				$airsyncbasebody->data = $plainnote;
			}
			if ($airsyncbasebody->type != 3 && (!isset($airsyncbasebody->data) || strlen($airsyncbasebody->data) == 0))
			{
				$airsyncbasebody->data = " ";
			}
		}
	}

	/**
	 * Convert received messagenote to egroupware plaintext note
	 *
	 * @param string $body the plain body received
	 * @param string $rtf the rtf body data
	 * @param SyncBaseBody $airsyncbasebody  object received from client
	 *
	 * @return string plaintext for eGroupware
	 */
	public function messagenote2note($body, $rtf, $airsyncbasebody)
	{
		if (isset($airsyncbasebody))
		{
			switch($airsyncbasebody->type)
			{
				case '3' :	$rtf = $airsyncbasebody->data;
							//error_log("Airsyncbase RTF Body");
							break;
				case '1' :	$body = $airsyncbasebody->data;
							//error_log("Airsyncbase Plain Body");
							break;
			}
		}
		// Nokia MfE 2.9.158 sends contact notes with RTF and Body element.
		// The RTF is empty, the body contains the note therefore we need to unpack the rtf
		// to see if it is realy empty and in case not, take the appointment body.
		/*if (isset($message->rtf))
		{
			error_log("RTF Body");
			$rtf_body = new rtf ();
			$rtf_body->loadrtf(base64_decode($rtf));
			$rtf_body->output("ascii");
			$rtf_body->parse();
			if (isset($body) && isset($rtf_body->out) && $rtf_body->out == "" && $body != "")
			{
				unset($rtf);
			}
			if($rtf_body->out <> "") $body=$rtf_body->out;
		}*/
		return $body;
	}

	/**
	 * Run and return settings from all plugins
	 *
	 * @param array|string $hook_data
	 * @return array with settings from all plugins
	 */
	public function egw_settings($hook_data)
	{
		return $this->run_on_all_plugins('egw_settings',array(),$hook_data);
	}

	/**
	 * Run and return verify_settings from all plugins
	 *
	 * @param array|string $hook_data
	 * @return array with error-messages from all plugins
	 */
	public function verify_settings($hook_data)
	{
		return $this->run_on_all_plugins('verify_settings', array(), $hook_data);
	}

	/**
	 * Plugins to use, filled by setup_plugins
	 *
	 * @var array
	 */
	private $plugins;

	/**
	 * Run a certain method on the plugin for the given id
	 *
	 * @param string $method
	 * @param string $id will be first parameter to method
	 * @param optional further parameters
	 * @return mixed
	 */
	public function run_on_plugin_by_id($method,$id)
	{
		$this->setup_plugins();

		$this->splitID($id, $type, $folder);

		if (is_numeric($type))
		{
			$type = 'mail';
		}
		$params = func_get_args();
		array_shift($params);	// remove $method

		$ret = false;
		if (isset($this->plugins[$type]) && method_exists($this->plugins[$type], $method))
		{
			//error_log($method.' called with Params:'.array2string($params));
			$ret = call_user_func_array(array($this->plugins[$type], $method),$params);
		}
		//error_log(__METHOD__."('$method','$id') type=$type, folder=$folder returning ".array2string($ret));
		return $ret;
	}

	/**
	 * Run a certain method on all plugins
	 *
	 * @param string $method
	 * @param mixed $agregate=array() if array given array_merge is used, otherwise +=
	 * 	or 'return-first' to return result from first matching plugin returning not null, false or '' result
	 * @param optional parameters
	 * @return mixed agregated result
	 */
	public function run_on_all_plugins($method,$agregate=array())
	{
		//error_log(__METHOD__."('$method', ".array2string($agregate).")");
		$this->setup_plugins();

		$params = func_get_args();
		array_shift($params); array_shift($params);	// remove $method+$agregate

		foreach($this->plugins as $plugin)
		{
			if (method_exists($plugin, $method))
			{
				debugLog(__METHOD__."() calling ".get_class($plugin).'::'.$method);
				$result = call_user_func_array(array($plugin, $method),$params);
				debugLog(__METHOD__."() calling ".get_class($plugin).'::'.$method.' returning '.array2string($result));

				if (is_array($agregate))
				{
					$agregate = array_merge($agregate,$result);
					error_log(__METHOD__."('$method', , ".array2string($params).") result plugin::$method=".array2string($result).' --> agregate='.array2string($agregate));
				}
				elseif ($agregate === 'return-first')
				{
					if ($result)
					{
						$agregate = $result;
						break;
					}
				}
				else
				{
					error_log(__METHOD__."('$method') agg:".array2string($agregate).' res:'.array2string($result));
					$agregate += (is_bool($agregate)? (bool) $result:$result);
				}
			}
		}
		if ($agregate === 'return-first') $agregate = false;
		error_log(__METHOD__."('$method') returning ".array2string($agregate));
		return $agregate;
	}

	/**
	 * Instanciate all plugins the user has application rights for
	 */
	private function setup_plugins()
	{
		if (isset($this->plugins)) return;

		$this->plugins = array();
		if (isset($GLOBALS['egw_info']['user']['apps'])) $apps = array_keys($GLOBALS['egw_info']['user']['apps']);
		if (!isset($apps))	// happens during setup
		{
			$apps = array('addressbook', 'calendar', 'mail', 'infolog'/*, 'filemanager'*/);
		}
		// allow apps without user run-rights to hook into eSync
		if (($hook_data = $GLOBALS['egw']->hooks->process('esync_extra_apps', array(), true)))	// true = no perms. check
		{
			foreach($hook_data as $app => $extra_apps)
			{
				if ($extra_apps) $apps = array_unique(array_merge($apps, (array)$extra_apps));
			}
		}
		foreach($apps as $app)
		{
			if (strpos($app,'_')!==false) continue;
			$class = $app.'_zpush';
			if (class_exists($class))
			{
				$this->plugins[$app] = new $class($this);
			}
		}
		//error_log(__METHOD__."() hook_data=".array2string($hook_data).' returning '.array2string(array_keys($this->plugins)));
	}

    /**
     * Returns the waste basket
     *
     * The waste basked is used when deleting items; if this function returns a valid folder ID,
     * then all deletes are handled as moves and are sent to the backend as a move.
     * If it returns FALSE, then deletes are handled as real deletes
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket()
	{

	}

	/**
     * Deletes a folder
     *
     * @param string        $id
     * @param string        $parent         is normally false
     *
     * @access public
     * @return boolean                      status - false if e.g. does not exist
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     */
    public function DeleteFolder($id, $parentid)
	{

	}

    /**
     * Indicates which AS version is supported by the backend.
     * By default AS version 2.5 (ASV_25) is returned (Z-Push 1 standard).
     * Subclasses can overwrite this method to set another AS version
     *
     * @access public
     * @return string       AS version constant
     */
    public function GetSupportedASVersion()
	{
        return ZPush::ASV_14;
    }

	/**
	 * Returns a IStateMachine implementation used to save states
	 * The default StateMachine should be used here, so, false is fine
	 *
	 * @access public
	 * @return boolean/object
	 */
	public function GetStateMachine()
	{
		return new activesync_statemachine($this);
	}
}

/**
 * Plugin interface for EGroupware application backends
 *
 * Apps can implement it in a class called appname_activesync, to participate in active sync.
 */
interface activesync_plugin_write extends activesync_plugin_read
{
	/**
	 *  Creates or modifies a folder
	 *
	 * @param $id of the parent folder
	 * @param $oldid => if empty -> new folder created, else folder is to be renamed
	 * @param $displayname => new folder name (to be created, or to be renamed to)
	 * @param type => folder type, ignored in IMAP
	 *
	 * @return stat | boolean false on error
	 *
	 */
	public function ChangeFolder($id, $oldid, $displayname, $type);

	/**
	 * Deletes (really delete) a Folder
	 *
	 * @param $parentid of the folder to delete
	 * @param $id of the folder to delete
	 *
	 * @return
	 * @TODO check what is to be returned
	 *
	 */
	public function DeleteFolder($parentid, $id);

	/**
	 * Changes or adds a message on the server
	 *
	 * @param $folderid
	 * @param $id for change | empty for create new
	 * @param SyncXXX $message object to SyncObject to create
     * @param ContentParameters   $contentParameters
	 *
	 * @return $stat whatever would be returned from StatMessage
	 *
	 * This function is called when a message has been changed on the PDA. You should parse the new
	 * message here and save the changes to disk. The return value must be whatever would be returned
	 * from StatMessage() after the message has been saved. This means that both the 'flags' and the 'mod'
	 * properties of the StatMessage() item may change via ChangeMessage().
	 * Note that this function will never be called on E-mail items as you can't change e-mail items, you
	 * can only set them as 'read'.
	 */
	public function ChangeMessage($folderid, $id, $message, $contentParameters);

	/**
	 * Moves a message from one folder to another
	 *
	 * @param $folderid of the current folder
	 * @param $id of the message
	 * @param $newfolderid
     * @param ContentParameters   $contentParameters
	 *
	 * @return $newid as a string | boolean false on error
	 *
	 * After this call, StatMessage() and GetMessageList() should show the items
	 * to have a new parent. This means that it will disappear from GetMessageList() will not return the item
	 * at all on the source folder, and the destination folder will show the new message
	 *
	 */
	public function MoveMessage($folderid, $id, $newfolderid, $contentParameters);

    /**
     * Called when the user has requested to delete (really delete) a message. Usually
     * this means just unlinking the file its in or somesuch. After this call has succeeded, a call to
     * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the mobile
     * as it will be seen as a 'new' item. This means that if this method is not implemented, it's possible to
     * delete messages on the PDA, but as soon as a sync is done, the item will be resynched to the mobile
     *
     * @param string              $folderid             id of the folder
     * @param string              $id                   id of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id, $contentParameters);

    /**
     * Changes the 'read' flag of a message on disk. The $flags
     * parameter can only be '1' (read) or '0' (unread). After a call to
     * SetReadFlag(), GetMessageList() should return the message with the
     * new 'flags' but should not modify the 'mod' parameter. If you do
     * change 'mod', simply setting the message to 'read' on the mobile will trigger
     * a full resync of the item from the server.
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param int                 $flags               read flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
	public function SetReadFlag($folderid, $id, $flags, $contentParameters);

	/**
	 * modify olflags (outlook style) flag of a message
	 *
	 * @param $folderid
	 * @param $id
	 * @param $flags
	 *
	 *
	 * @DESC The $flags parameter must contains the poommailflag Object
	 */
	public function ChangeMessageFlag($folderid, $id, $flags);
}

/**
 * Plugin interface for EGroupware application backends
 *
 * Apps can implement it in a class called appname_activesync, to participate in acitve sync.
 */
interface activesync_plugin_read
{
	/**
	 * Constructor
	 *
	 * @param BackendEGW $backend
	 */
	public function __construct(BackendEGW $backend);

	/**
	 *  This function is analogous to GetMessageList.
	 */
	public function GetFolderList();

	/**
	 * Return a changes array
	 *
	 * if changes occurr default diff engine computes the actual changes
	 *
	 * @param string $folderid
	 * @param string &$syncstate on call old syncstate, on return new syncstate
	 * @return array|boolean false if $folderid not found, array() if no changes or array(array("type" => "fakeChange"))
	 */
	public function AlterPingChanges($id, &$synckey);

	/**
	 * Get Information about a folder
	 *
	 * @param string $id
	 * @return SyncFolder|boolean false on error
	 */
	public function GetFolder($id);

	/**
	 * Return folder stats. This means you must return an associative array with the
	 * following properties:
	 *
	 * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
	 *		 How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
	 * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
	 * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
	 *		  the folder has not changed. In practice this means that 'mod' can be equal to the folder name
	 *		  as this is the only thing that ever changes in folders. (the type is normally constant)
	 *
	 * @return array with values for keys 'id', 'parent' and 'mod'
	 */
	function StatFolder($id);

	/**
	 * Return an list (array) of all messages in a folder
	 *
	 * @param $folderid
	 * @param $cutoffdate=NULL
	 *
	 * @return $array
	 *
	 * @DESC
	 * each entry being an associative array with the same entries as StatMessage().
	 * This function should return stable information; ie
	 * if nothing has changed, the items in the array must be exactly the same. The order of
	 * the items within the array is not important though.
	 *
	 * The cutoffdate is a date in the past, representing the date since which items should be shown.
	 * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
	 * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
	 * will work OK apart from that.
	 */
	public function GetMessageList($folderid, $cutoffdate=NULL);

	/**
	 * Get Message stats
	 *
	 * @param $folderid
	 * @param $id
	 *
	 * @return $array
	 *
	 * StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
	 * 'id'     => Server unique identifier for the message. Again, try to keep this short (under 20 chars)
	 * 'flags'  => simply '0' for unread, '1' for read
	 * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
	 *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
	 *             time for this field, which will change as soon as the contents have changed.
	 */
	public function StatMessage($folderid, $id);

	/**
	 * Get specified item from specified folder.
	 * @param string $folderid
	 * @param string $id
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
	 * @return $messageobject|boolean false on error
	 */
	function GetMessage($folderid, $id, $contentparameters);

	/**
	 * Settings / Preferences like the usualy settings hook in egroupware
	 *
	 * Names should be prefixed with the application name and a dash '-', to not conflict with other plugins
	 *
	 * @return array name => array with values for keys: type, label, name, help, values, default, ...
	 */
	function egw_settings($hook_data);
}

/**
 * Plugin that can send mail
 */
interface activesync_plugin_sendmail
{
	/**
	 * Sends a message which is passed as rfc822. You basically can do two things
	 * 1) Send the message to an SMTP server as-is
	 * 2) Parse the message yourself, and send it some other way
	 * It is up to you whether you want to put the message in the sent items folder. If you
	 * want it in 'sent items', then the next sync on the 'sent items' folder should return
	 * the new message as any other new message in a folder.
	 *
     * @param array $smartdata = IMAP-SendMail: SyncSendMail (
     *        (S) clientid => SendMail-xyz123456789
     *        (S) saveinsent => empty
     *        (S) replacemime => null
     *        (S) accountid => null
     *        (S) source => SyncSendMailSource (
     *                                (S) folderid => FOLDERID
     *                                (S) itemid => 1234567
     *                                (S) longid => null
     *                                (S) instanceid => null
     *                                unsetVars(Array) size: 0
     *                                flags => false
     *                                content => null
     *                        )
     *        (S) mime => MIMEMESSAGE
     *        (S) replyflag => boolean or null
     *        (S) forwardflag => boolean or null
     *        unsetVars(Array) size: 0
     *        flags => false
     *        content => null
     *)
	 *
	 * @see eg. BackendIMAP::SendMail()
	 * @todo implement either here or in fmail backend
	 * 	(maybe sending here and storing to sent folder in plugin, as sending is supposed to always work in EGroupware)
	 */
	function SendMail($smartdata);
}

/**
 * Plugin that supports MeetingResponse method
 *
 * Plugin can call MeetingResponse method of backend with $requestid containing an iCal to let calendar plugin add the event
 */
interface activesync_plugin_meeting_response
{
	/**
	 * Process response to meeting request
	 *
	 * @see BackendDiff::MeetingResponse()
	 * @param string $folderid folder of meeting request mail
	 * @param int|string $requestid uid of mail with meeting request
	 * @param int $response 1=accepted, 2=tentative, 3=decline
	 * @return int|boolean id of calendar item, false on error
	 */
	function MeetingResponse($folderid, $requestid, $response);
}

/**
 * Plugin that supplies additional (to mail) meeting requests
 *
 * These meeting requests should have negative id's to not conflict with uid's of mail!
 *
 * Backend merges these meeting requests into the inbox, even if mail is completly disabled
 */
interface activesync_plugin_meeting_requests extends activesync_plugin_meeting_response
{
	/**
	 * List all meeting requests / invitations of user NOT having a UID in $not_uids (already received by email)
	 *
	 * @param array $not_uids
	 * @param int $cutoffdate=null
	 * @return array
	 */
	function GetMeetingRequests(array $not_uids, $cutoffdate=NULL);

	/**
	 * Stat a meeting request
	 *
	 * @param int $id negative! id
	 * @return array
	 */
	function StatMeetingRequest($id);

	/**
	 * Return a meeting request as AS SyncMail object
	 *
	 * @param int $id negative! cal_id
	 * @param int $truncsize
	 * @param int $bodypreference
	 * @param $optionbodypreference
	 * @param bool $mimesupport
	 * @return SyncMail
	 */
	function GetMeetingRequest($id, $truncsize, $bodypreference=false, $optionbodypreference=false, $mimesupport = 0);
}

/**
 * Plugin interface for EGroupware application backends to implement a global addresslist search
 *
 * Apps can implement it in a class called appname_activesync, to participate in active sync.
 */
interface activesync_plugin_search_gal
{
	/**
	 * Search global address list for a given pattern
	 *
	 * @param string $searchquery
	 * @return array with just rows (no values for keys rows, status or global_search_status!)
	 */
	public function getSearchResultsGAL($searchquery);
}

/**
 * Plugin interface for EGroupware application backends to implement a mailbox search
 *
 * Apps can implement it in a class called appname_activesync, to participate in active sync.
 */
interface activesync_plugin_search_mailbox
{
	/**
	 * Search mailbox for a given pattern
	 *
	 * @param string $searchquery
	 * @return array with just rows (no values for keys rows, status or global_search_status!)
	 */
	public function getSearchResultsMailbox($searchquery);
}

/**
 * Plugin interface for EGroupware application backends to implement a document library search
 *
 * Apps can implement it in a class called appname_activesync, to participate in active sync.
 */
interface activesync_plugin_search_documentlibrary
{
	/**
	 * Search document library for a given pattern
	 *
	 * @param string $searchquery
	 * @return array with just rows (no values for keys rows, status or global_search_status!)
	 */
	public function getSearchResultsDocumentLibrary($searchquery);
}
