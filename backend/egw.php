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
	 * Get Information about a folder
	 *
	 * @param string $id
	 * @return SyncFolder|boolean false on error
	 */
	function GetFolder($id)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $id);
	}

	/* Return folder stats. This means you must return an associative array with the
	 * following properties:
	 * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
	 *		 How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
	 * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
	 * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
	 *		  the folder has not changed. In practice this means that 'mod' can be equal to the folder name
	 *		  as this is the only thing that ever changes in folders. (the type is normally constant)
	 */
	function StatFolder($id)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $id);
	}
	
	/* Should return a list (array) of messages, each entry being an associative array
     * with the same entries as StatMessage(). This function should return stable information; ie
     * if nothing has changed, the items in the array must be exactly the same. The order of
     * the items within the array is not important though.
     *
     * The cutoffdate is a date in the past, representing the date since which items should be shown.
     * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
     * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
     * will work OK apart from that.
  */
	function GetMessageList($id, $cutoffdate=NULL)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $id, $cutoffdate);
	}


	function GetMessage($folderid, $id, $truncsize, $bodypreference=false, $mimesupport = 0)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id, $truncsize, $bodypreference=false, $mimesupport = 0);
	}
	
	
	function StatMessage($folderid, $id)
	{
		return $this->run_on_plugin_by_id(__FUNCTION__, $folderid, $id);
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

	// Called when a message has to be sent and the message needs to be saved to the 'sent items'
	// folder
	function SendMail($rfc822, $smartdata=array(), $protocolversion = false) {}

	/**
	 * Returns array of items which contain searched for information
	 *
	 * @param array $searchquery
	 * @param string $searchname
	 *
	 * @return array
	 */
	function getSearchResults($searchquery,$searchname)
	{
		// debugLog("EGW:getSearchResults : query: ". $searchquery . " : searchname : ". $searchname);
		switch (strtoupper($searchname)) {
			case "GAL":	return $this->getSearchResultsGAL($searchquery);
			case "MAILBOX":	return $this->getSearchResultsMailbox($searchquery);
		  	case "DOCUMENTLIBRARY"	: return $this->getSearchResultsDocumentLibrary($searchquery);
		  	default: debugLog (__METHOD__." unknown searchname ". $searchname);
		}
	}

	function getSearchResultsGAL($searchquery) 							//TODO: search range not verified, limits might be a good idea
	{
		$boAddressbook = new addressbook_bo();
		$egw_found = $boAddressbook->link_query($searchquery);  //TODO: any reasonable options ?
		$items['rows'] = array();
		foreach($egw_found as $key=>$value)
		{
		  	$item = array();
		  	$contact = $boAddressbook->read ($key);
		  	$item["username"] = $contact['n_family'];
			$item["fullname"] = $contact['n_fn'];
			if (strlen(trim($item["fullname"])) == 0) $item["fullname"] = $item["username"];
			$item["emailaddress"] = $contact['email'] ? $contact['email'] : '' ;
			$item["nameid"] = $searchquery;
			$item["phone"] = $contact['tel_work'] ? $contact['tel_work'] : '' ;
			$item["homephone"] = $contact['tel_home'] ? $contact['tel_home'] : '';
			$item["mobilephone"] = $contact['tel_cell'];
			$item["company"] = $contact['org_name'];
			$item["office"] = 'Office';
			$item["title"] = $contact['title'];

		  	//do not return users without email
			if (strlen(trim($item["emailaddress"])) == 0) continue;
			  	array_push($items['rows'], $item);
		}
		$items['status']=1;
		$items['global_search_status'] = 1;
		return $items;
	}

	function getSearchResultsMailbox($searchquery)
	{
		return false;
	}

	function getSearchResultsDocumentLibrary($searchquery)
	{
		return false;
	}

	function getDeviceRWStatus($user, $pass, $devid)
	{
		return false;
	}

	const TYPE_ADDRESSBOOK = 1;
	const TYPE_CALENDAR = 2;
	const TYPE_MAIL = 10;

	/**
	 * Create a max. 32 hex letter ID, current 20 chars are used
	 *
	 * @param int|string $type appname or integer mail account id
	 * @param int $folder integer folder ID
	 * @param int $id integer item ID
	 * @return string
	 * @throws egw_exception_wrong_parameter
	 */
	public function createID($type,$folder,$id=0)
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

		$str = sprintf('%04X%08X%08X',$type,$folder,$id);

		//debugLog(__METHOD__."('$t','$f',$id) type=$type --> '$str'");

		return $str;
	}

	/**
	 * Split an ID string into $app, $folder and $id
	 *
	 * @param string $str
	 * @param string|int &$type on return appname or integer mail account ID
	 * @param int &$folder on return integer folder ID
	 * @param int &$id=null on return integer item ID
	 * @throws egw_exception_wrong_parameter
	 */
	public function splitID($str,&$type,&$folder,&$id=null)
	{
		$type = hexdec(substr($str,0,4));
		$folder = hexdec(substr($str,4,8));
		$id = hexdec(substr($str,12,8));

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
		//debugLog(__METHOD__."('$str','$type','$folder',$id)");
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
	
	/* Should return a list (array) of messages, each entry being an associative array
     * with the same entries as StatMessage(). This function should return stable information; ie
     * if nothing has changed, the items in the array must be exactly the same. The order of
     * the items within the array is not important though.
     *
     * The cutoffdate is a date in the past, representing the date since which items should be shown.
     * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
     * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
     * will work OK apart from that.
     */
	public function GetMessageList($folderID, $cutoffdate=NULL);
	
	/* StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
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
 * Plugin interface for EGroupware application backends to implement a global search
 *
 * Apps can implement it in a class called appname_activesync, to participate in active sync.
 */
interface activesync_plugin_search
{
	/**
	 * Constructor
	 *
	 * @param BackendEGW $backend
	 */
	public function __construct(BackendEGW $backend);

	/**
	 * Returns array of items which contain searched for information
	 *
	 * @param array $searchquery
	 * @param string $searchname
	 *
	 * @return array
	 */
	public function getSearchResults($searchquery,$searchname);
}
