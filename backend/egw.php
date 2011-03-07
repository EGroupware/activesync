<?php
/**
 * EGroupware: ActiveSync access: Z-Push backend for EGroupware
 *
 * @link http://www.egroupware.org
 * @package activesync
 * @author Ralf Becker <rb@stylite.de>
 * @author Klaus Leithoff <kl@stylite.de>
 * @author Philip Herbert <philip@knauber.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

include_once('diffbackend.php');

/**
 * Z-Push backend for EGroupware
 *
 * Uses EGroupware application specific plugins, eg. felamimail_activesync class
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
	 */
	public function Logon($username, $domain, $password)
	{
		// check credentials and create session
		if (!($this->egw_sessionID = $GLOBALS['egw']->session->create($username,$password,'text',true)))	// true = no real session
		{
			debugLog(__METHOD__."() z-push authentication failed: ".$GLOBALS['egw']->session->cd_reason);
			return false;
		}
		if (!isset($GLOBALS['egw_info']['user']['apps']['activesync']))
		{
			debugLog(__METHOD__."() z-push authentication failed: NO run rights for activesync application!");
			return false;
		}
   		$GLOBALS['egw_info']['flags']['currentapp'] = 'activesync';
   		debugLog(__METHOD__."('$username','$domain',...) logon SUCCESS");

   		$this->_loggedin = TRUE;

		return true;
	}

	/**
	 * Called before closing connection
	 */
	public function Logoff()
	{
		if ($this->mail) $this->mail->closeConnection();
		unset($this->mail);

		if (!$GLOBALS['egw']->session->destroy($this->egw_sessionID,"")) {
			debugLog ("nothing to destroy");
		}
		$this->_loggedin = FALSE;

		debugLog ("LOGOFF");
	}

	/**
	 *  This function is analogous to GetMessageList.
	 */
	function GetFolderList()
	{
		$folderlist = array();

		$folderlist = $this->run_on_all_plugins(__FUNCTION__);

		//debugLog(__METHOD__."() returning ".array2string($folderlist));

		return $folderlist;
	}

	/**
	 * Switches alter ping handling on or off
	 *
	 * @see BackendDiff::AlterPing()
	 * @return boolean
	 */
	function AlterPing()
	{
		return true;
	}

	/**
	 * Get Information about a folder
	 *
	 * @param string $id
	 * @return SyncFolder|boolean false on error
	 */
	function GetFolder($id)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $id);
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
		return $this->run_on_plugin_by_id(__FUNCTION__, $id);
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
		//debugLog(__METHOD__.__LINE__.' ID:'.$id.' Cutoffdate:'.$cutoffdate. function_backtrace());
		return $this->run_on_plugin_by_id(__FUNCTION__, $id, $cutoffdate);
	}

	/**
	 * Get specified item from specified folder.
	 *
	 * @param string $folderid
	 * @param string $id
	 * @param int $truncsize
	 * @param int $bodypreference
	 * @param bool $mimesupport
	 * @return $messageobject|boolean false on error
	 */
	function GetMessage($folderid, $id, $truncsize, $bodypreference=false, $mimesupport = 0)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $truncsize, $bodypreference, $mimesupport);
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

	/**
	 * StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
     * 'id'     => Server unique identifier for the message. Again, try to keep this short (under 20 chars)
     * 'flags'     => simply '0' for unread, '1' for read
     * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
     *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
     *             time for this field, which will change as soon as the contents have changed.
     *
     * @param string $folderid
     * @param int|array $contact contact id or array
     * @return array
     */
	function StatMessage($folderid, $id)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id);
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

		if (is_numeric($type)) $type = 'felamimail';

		$ret = false;
		if (isset($this->plugins[$type]) && method_exists($this->plugins[$type], __FUNCTION__))
		{
			$ret = call_user_func_array(array($this->plugins[$type], __FUNCTION__), array($folderid, &$syncstate));
		}
		debugLog(__METHOD__."('$folderid','".array2string($syncstate)."') type=$type, folder=$folder returning ".array2string($ret));
		return $ret;
	}

	function ChangeMessage($folderid, $id, $message)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $message);
	}

	function MoveMessage($folderid, $id, $newfolderid)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $newfolderid);
	}

	function DeleteMessage($folderid, $id)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id);
	}

	function SetReadFlag($folderid, $id, $flag)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $flag);
	}


	/**
	 * START ADDED dw2412 Settings Support
	 */
	function setSettings($request,$devid)
	{
		if (isset($request["oof"])) {
			if ($request["oof"]["oofstate"] == 1) {
				// in case oof should be switched on do it here
				// store somehow your oofmessage in case your system supports.
				// response["oof"]["status"] = true per default and should be false in case
				// the oof message could not be set
				$response["oof"]["status"] = true;
			} else {
				// in case oof should be switched off do it here
				$response["oof"]["status"] = true;
			}
		}
		if (isset($request["deviceinformation"])) {
			//error_log(print_r($request["deviceinformation"]));
			// in case you'd like to store device informations do it here.
			$response["deviceinformation"]["status"] = true;
		}
		if (isset($request["devicepassword"])) {
			// in case you'd like to store device informations do it here.
			$response["devicepassword"]["status"] = true;
		}

		return $response;
	}

	function getSettings ($request,$devid)
	{
		if (isset($request["userinformation"])) {
			$response["userinformation"]["status"] = 1;
			$response["userinformation"]["emailaddresses"][] = $GLOBALS['egw_info']['user']['email'];
		} else {
			$response["userinformation"]["status"] = false;
		};
		if (isset($request["oof"])) {
			$response["oof"]["status"] 	= 0;
		};
		return $response;
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
		debugLog(__METHOD__."('$rfc822', ".array2string($smartdata).", $protocolversion)");
		$this->setup_plugins();
		$type = 'felamimail';
		$method = 'SendMail';
		$ret = false;
		if (isset($this->plugins[$type]) && method_exists($this->plugins[$type], $method))
		{
			//debugLog(__METHOD__." Plugin is set ");
			$params = array($rfc822, $smartdata, $protocolversion);
			//error_log($method.' called with Params:'.array2string($params));
			$ret = call_user_func_array(array($this->plugins[$type], $method),$params);
		}
		return $ret;	// fake sending mail worked
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
		// debugLog("EGW:getSearchResults : query: ". $searchquery . " : searchname : ". $searchname);
		switch (strtoupper($searchname)) {
			case 'GAL':
				$rows = self::run_on_all_plugins('getSearchResultsGAL',array(),$searchquery);
				break;
			case 'MAILBOX':
				$rows = self::run_on_all_plugins('getSearchResultsMailbox',array(),$searchquery);
				break;
		  	case 'DOCUMENTLIBRARY':
				$rows = self::run_on_all_plugins('getSearchDocumentLibrary',array(),$searchquery);
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
		error_log(__METHOD__."('$searchquery', '$searchname') returning ".count($result['rows']).' rows = '.array2string($result));
		return $result;
	}

	/**
	 *
	 * @see BackendDiff::getDeviceRWStatus()
	 */
	function getDeviceRWStatus($user, $pass, $devid)
	{
		return false;
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
			case 'mail': case 'felamimail':
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

		//debugLog(__METHOD__."('$t','$f') type=$type --> '$str'");

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
	 * @throws egw_exception_wrong_parameter
	 */
	public function splitID($str,&$type,&$folder)
	{
		$type = hexdec(substr($str,0,4));
		$folder = hexdec(substr($str,4,8));
		// convert 32bit negative numbers on a 64bit system to a 64bit negative number
		if ($folder > 0x7fffffff) $folder -= 0x100000000;

		switch($type)
		{
			case self::TYPE_ADDRESSBOOK:
				$type = 'addressbook';
				break;
			case self::TYPE_CALENDAR:
				$type = 'calendar';
				break;
			default:
				if ($type < self::TYPE_MAIL)
				{
					throw new egw_exception_wrong_parameter("Unknown type='$type'!");
				}
				$type -= self::TYPE_MAIL;
				break;
		}
		// debugLog(__METHOD__."('$str','$type','$folder')");
	}

	/**
	 * Convert note to requested bodypreference format and truncate if requested
	 *
	 * @param $note string containing the plaintext message
	 * @param $bodypreference object
	 * @param &$airsyncbasebody the airsyncbasebody object to send to the client
	 *
	 * @return string plain textbody for message or false
	 */
	public function note2messagenote($note, $bodypreference, &$airsyncbasebody)
	{
		error_log (__METHOD__);
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
	 * @param $body the plain body received
	 * @param $body the rtf body data
	 * @param $airsyncbasebody  object received from client
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
							error_log("Airsyncbase RTF Body");
							break;
				case '1' :	$body = $airsyncbasebody->data;
							error_log("Airsyncbase Plain Body");
							break;
			}
		}
		// Nokia MfE 2.9.158 sends contact notes with RTF and Body element.
		// The RTF is empty, the body contains the note therefore we need to unpack the rtf
		// to see if it is realy empty and in case not, take the appointment body.
		if (isset($message->rtf))
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
		}
		return $body;
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
	private function run_on_plugin_by_id($method,$id)
	{
		$this->setup_plugins();

		$this->splitID($id, $type, $folder);

		if (is_numeric($type)) $type = 'felamimail';

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
	 * @param optional parameters
	 * @return mixed agregated result
	 */
	private function run_on_all_plugins($method,$agregate=array())
	{
		$this->setup_plugins();

		$params = func_get_args();
		array_shift($params); array_shift($params);	// remove $method+$agregate

		foreach($this->plugins as $plugin)
		{
			if (method_exists($plugin, $method))
			{
				$result = call_user_func_array(array($plugin, $method),$params);
				if (is_array($agregate))
				{
					$agregate = array_merge($agregate,$result);
				}
				else
				{
					$agregate += $result;
				}
			}
		}
		//error_log(__METHOD__."('$method') returning ".array2string($agregate));
		return $agregate;
	}

	/**
	 * Instanciate all plugins the user has application rights for
	 */
	private function setup_plugins()
	{
		if (isset($plugins)) return;

		$this->plugins = array();
		foreach($GLOBALS['egw_info']['user']['apps'] as $app => $data)
		{
			$class = $app.'_activesync';
			if (class_exists($class))
			{
				$this->plugins[$app] = new $class($this);
			}
		}
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
     * @param $message object to SyncObject to create
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
    public function ChangeMessage($folderid, $id, $message);

    /**
     * Moves a message from one folder to another
     *
     * @param $folderid of the current folder
     * @param $id of the message
     * @param $newfolderid
     *
     * @return $newid as a string | boolean false on error
     *
     * After this call, StatMessage() and GetMessageList() should show the items
     * to have a new parent. This means that it will disappear from GetMessageList() will not return the item
     * at all on the source folder, and the destination folder will show the new message
     *
     */
    public function MoveMessage($folderid, $id, $newfolderid);


    /**
     * Delete (really delete) a message in a folder
     *
     * @param $folderid
     * @param $id
     *
     * @TODO check what is to be returned
     *
     * @DESC After this call has succeeded, a call to
     * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the PDA
     * as it will be seen as a 'new' item. This means that if you don't implement this function, you will
     * be able to delete messages on the PDA, but as soon as you sync, you'll get the item back
     */
    public function DeleteMessage($folderid, $id);

    /**
     * modify read flag of a message
     *
     * @param $folderid
     * @param $id
     * @param $flags
     *
     *
     * @DESC The $flags parameter can only be '1' (read) or '0' (unread)
     */
    public function SetReadFlag($folderid, $id, $flags);

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
     * 'flags'     => simply '0' for unread, '1' for read
     * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
     *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
     *             time for this field, which will change as soon as the contents have changed.
     */
	public function StatMessage($folderid, $id);

	/**
	 * Get specified item from specified folder.
	 * @param string $folderid
	 * @param string $id
	 * @param int $truncsize
	 * @param int $bodypreference
	 * @param bool $mimesupport
	 * @return $messageobject|boolean false on error
	 */
	function GetMessage($folderid, $id, $truncsize, $bodypreference=false, $mimesupport = 0);

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
