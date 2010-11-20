<?php
/***********************************************
* File	  :   egw.php
* Project   :   Z-Push/eGroupware
* Descr	 :
*
* Created   :   01.11.2010
*
*
* This file is distributed under GPL v2.
*
************************************************/

include_once('diffbackend.php');

// The is an improved version of mimeDecode from PEAR that correctly
// handles charsets and charset conversion
include_once('mime.php');
include_once('mimeDecode.php');
require_once('z_RFC822.php');
require_once ('../felamimail/inc/class.bofelamimail.inc.php');

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
	 * Instance of bofelamimail
	 *
	 * @var bofelamimail
	 */
	var $mail;

	// Returns TRUE if the logon succeeded, FALSE if not
	function Logon($username, $domain, $password)
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

		$this->mail = new bofelamimail ("UTF-8",false);
    	$this->mail->openConnection(0,false);

    	return true;
	}

	// called before closing connection
	function Logoff()
	{
		if ($this->mail) $this->mail->closeConnection();
		unset($this->mail);

		if (!$GLOBALS['egw']->session->destroy($this->egw_sessionID,"")) {
			debugLog ("nothing to destroy");
		}
		debugLog ("LOGOFF");
	}

	var $folders;

    /**
     *  This function is analogous to GetMessageList.
     */
	function GetFolderList()
	{
		$folderlist = array();

    	if (!isset($this->folders)) $this->folders = $this->mail->getFolderObjects(true,false);

    	foreach ($this->folders as $key => $folder) {
    		// debugLog(array2string($folder));
    		//debugLog ("DisplayName: " . $folder->shortDisplayName);
    		//debugLog ("FolderName : " . $folder->folderName);
    		//debugLog ("Delimiter  : " . $folder->delimiter);
    		$buf = explode ($folder->delimiter, $folder->folderName);

    		$folderentry = array();
    		$folderentry["parent"] = $this->getParentID($key);
    		$folderentry["mod"] =  $folder->shortDisplayName;
    		$folderentry["id"] = $this->createID ("mail", $folder->folderName, 0);

    		$folderlist[] = $folderentry;
    	}

  		// TODO : other apps

		//debugLog(__METHOD__."() returning ".array2string($folderlist));

		return $folderlist;
	}

	/**
	 * Get ID of parent Folder or '0' for folders in root
	 *
	 * @param string $folder
	 * @return string
	 */
	private function getParentID($folder)
	{
		if (!isset($this->folders)) $this->folders = $this->mail->getFolderObjects(true,false);

		$fmailFolder = $this->folders[$folder];
		if (!isset($fmailFolder)) return false;

		$parent = explode($fmailFolder->delimiter,$folder);
		array_pop($parent);
		$parent = implode($fmailFolder->delimiter,$parent);

		$id = $parent ? $this->createID("mail", $parent) : '0';
		//debugLog(__METHOD__."('$folder') --> parent=$parent --> $id");
		return $id;
	}

	/**
	 * Get Information about a folder
	 *
	 * @param string $id
	 * @return SyncFolder|boolean false on error
	 */
	function GetFolder($id)
	{
		static $last_id;
		static $folderObj;
		if (isset($last_id) && $last_id === $id) return $folderObj;

		try {
			$this->splitID($id, $type, $folder);
		}
		catch(Exception $e) {
			return $folderObj=false;
		}
		if (!isset($this->folders)) $this->folders = $this->mail->getFolderObjects(true,false);

		$fmailFolder = $this->folders[$folder];
		if (!isset($fmailFolder)) return $folderObj=false;

		$folderObj = new SyncFolder();
		$folderObj->serverid = $id;
		$folderObj->parentid = $this->getParentID($folder);
		$folderObj->displayname = $fmailFolder->shortDisplayName;

		// get folder-type
		foreach($this->folders as $inbox => $fmailFolder) break;
		if ($folder == $inbox)
		{
			$folderObj->type = SYNC_FOLDER_TYPE_INBOX;
		}
		elseif($this->mail->isDraftFolder($folder))
		{
			$folderObj->type = SYNC_FOLDER_TYPE_DRAFTS;
		}
		elseif($this->mail->isTrashFolder($folder))
		{
			$folderObj->type = SYNC_FOLDER_TYPE_WASTEBASKET;
		}
		elseif($this->mail->isSentFolder($folder))
		{
			$folderObj->type = SYNC_FOLDER_TYPE_SENTMAIL;
		}
		else
		{
			$folderObj->type = SYNC_FOLDER_TYPE_USER_MAIL;
		}
		//debugLog(__METHOD__."($id) --> $folder --> type=$folderObj->type, parentID=$folderObj->parentid, displayname=$folderObj->displayname");
		return $folderObj;
	}

    /* Return folder stats. This means you must return an associative array with the
     * following properties:
     * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
     *         How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
     * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
     * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
     *          the folder has not changed. In practice this means that 'mod' can be equal to the folder name
     *          as this is the only thing that ever changes in folders. (the type is normally constant)
     */
    function StatFolder($id) {
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    // START ADDED dw2412 Settings Support
    function setSettings($request,$devid) {
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
/*
	function GetHierarchyImporter()
	{
		return new ImportHierarchyChangesEGW($this->_defaultstore);
	}

	function GetContentsImporter($folderid)
	{
		return new ImportContentsChangesEGW($this->_session, $this->_defaultstore, hex2bin($folderid));
	}

	function GetExporter($folderid = false)
	{
		debugLog ("EGW:GetExporter : folderid : ". $folderid);
		if($folderid !== false)
			return new ExportChangesEGW($this->_session, $this->_defaultstore, hex2bin($folderid));
		else
			return new ExportChangesEGW($this->_session, $this->_defaultstore);
	}
*/
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
			case "GAL":		return $this->getSearchResultsGAL($searchquery);
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
	 * @param int $type
	 * @param int|string $folder
	 * @param int $id
	 * @return string
	 * @throws egw_exception_wrong_parameter
	 */
	public function createID($type,$folder,$id=0)
	{
		// get a nummeric $type
		switch($t=$type)
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
				if (!is_nummeric($type))
				{
					throw new egw_exception_wrong_parameter("type='$type' is NOT nummeric!");
				}
				$type += self::TYPE_MAIL;
				break;
		}

		if (!is_numeric($folder))
		{
			// convert string $folder in numeric id
			$folder = $this->folder2hash($type,$f=$folder);
		}

		$str = sprintf('%04X%08X%08X',$type,$folder,$id);

		//debugLog(__METHOD__."('$t','$f',$id) type=$type, folder=$folder --> '$str'");

		return $str;
	}

	/**
	 * Split an ID string into $app, $folder and $id
	 *
	 * @param string $str
	 * @param string|int &$type
	 * @param string|int &$folder
	 * @param int &$id
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
				// convert numeric folder-id back to folder name
				$folder = $this->hash2folder($type,$folder);
				$type -= self::TYPE_MAIL;
				break;
		}
		//debugLog(__METHOD__."('$str','$type','$folder',$id)");
	}

	/**
	 * Convert folder string to nummeric hash
	 *
	 * @param int $type
	 * @param string $folder
	 * @return int
	 */
	public function folder2hash($type,$folder)
	{
		if(!isset($this->folderHashes)) $this->readFolderHashes();

		if (($index = array_search($folder, (array)$this->folderHashes[$type])) === false)
		{
			// new hash
			$this->folderHashes[$type][] = $folder;
			$index = array_search($folder, (array)$this->folderHashes[$type]);

			// maybe later storing in on class destruction only
			$this->storeFolderHashes();
		}
		return $index;
	}

	/**
	 * Convert numeric hash to folder string
	 *
	 * @param int $type
	 * @param int $index
	 * @return string NULL if not used so far
	 */
	public function hash2folder($type,$index)
	{
		if(!isset($this->folderHashes)) $this->readFolderHashes();

		return $this->folderHashes[$type][$index];
	}

	public $folderHashes;

	/**
	 * Read hashfile from state dir
	 */
	private function readFolderHashes()
	{
		if (file_exists($file = $this->hashFile()) &&
			($hashes = file_get_contents($file)))
		{
			$this->folderHashes = unserialize($hashes);
		}
		else
		{
			$this->folderHashes = array();
		}
	}

	/**
	 * Store hashfile in state dir
	 *
	 * return int|boolean false on error
	 */
	private function storeFolderHashes()
	{
		return file_put_contents($this->hashFile(), serialize($this->folderHashes));
	}

	/**
	 * Get name of hashfile in state dir
	 *
	 * @throws egw_exception_assertion_failed
	 */
	private function hashFile()
	{
		if (!isset($this->_devid))
		{
			throw new egw_exception_assertion_failed(__METHOD__."() called without this->_devid set!");
		}
		return STATE_DIR.'/'.strtolower($this->_devid).'/'.$this->_devid.'.hashes';
	}
}


/*
require_once('../../phpgwapi/inc/class.egw_exception.inc.php');

$GLOBALS['egw_info']['server']['files_dir'] = '/var/lib/egroupware/default/files';
define('STATE_DIR',$GLOBALS['egw_info']['server']['files_dir'].'/activesync');

$backend = new BackendEGW();
$backend->_devid = 'test';

foreach(array('INBOX','Sent','Test','Test/Folder') as $folder)
{
	$hash = $backend->folder2hash(0, $folder);
	echo "<p>$folder --> $hash</p>\n";
}
echo "<pre>".print_r($backend->folderHashes,true)."</pre>\n";
*/


class ImportHierarchyChangesEGW  {
	var $_user;

	function ImportHierarchyChangesEGW($store) {

	}

	function Config($state, $flags = 0) {
		// Put the state information in a stream that can be used by ICS

	}

	function ImportFolderChange($id, $parent, $displayname, $type) {
		//create a new folder if $id is not set
	}

	function ImportFolderDeletion($id, $parent) {

	}

	function GetState() {
	}
};

class ExportChangesEGW  {




	var $_folderid;
	var $_store;
	var $_session;
	var $_backend;

	/**
	 * Reference to backend class
	 *
	 * @var BackendEGW
	 */
	var $backend;

	function ExportChangesEGW($session, $store, $folderid = false) {
		// Open a hierarchy or a contents exporter depending on whether a folderid was specified
		debugLog(__METHOD__ . " " . $session . " " . $store . " " . $folderid);


		$this->_session = $session;
		$this->_folderid = $folderid;
		$this->_store = $store;

		if ($folderid) {
			debugLog(__METHOD__ . " with folder ID");
		} else {
			debugLog(__METHOD__ . " no folder ID");
			$this->exporter = '';
		}
		$this->backend = $GLOBALS['backend'];
	}










	// CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
	function Config(&$importer, $mclass, $restrict, $syncstate, $flags, $truncation, $bodypreference) {


			// die("XX");
			$mail = new bofelamimail ();
			$mail->openConnection(0,false);
			error_log("mail_error :" .$mail->getErrorMessage);
			$folders = $mail->getFolderObjects(true,false);
		  error_log(print_r($folders,true));
			$mail->closeConnection;
			debugLog("*******************************");
			debugLog(__METHOD__);
			$this->_importer = &$importer;
		$this->_restrict = $restrict;
		$this->_syncstate = unserialize($syncstate);
		$this->_flags = $flags;
		$this->_truncation = $truncation;
				$this->_bodypreference = $bodypreference;

		$this->_changes = array();
		$this->_step = 0;

		$cutoffdate = $this->_getCutOffDate($restrict);

		error_log(print_r($this->_syncstate,true));
		error_log("*******************************");

		if($this->_folderid) {
			// Get the changes since the last sync
			debugLog("Initializing message diff engine");
		} else {
				debugLog("Getting List of Folders");
				$folderlist = $this->GetFolderList();
			if($folderlist === false)
				return false;

			if(!isset($this->_syncstate) || !$this->_syncstate)
				$this->_syncstate = array();

   					// $this->_changes = GetDiff($this->_syncstate, $folderlist);

						$this->_changes = $folderlist;

			debugLog("Found " . count($this->_changes) . " folder changes");


		}
	}

	function GetFolderList() {

		$folderlist = array();

		$mail = new bofelamimail ();
    	$mail->openConnection(0,false);
    	$folders = $mail->getFolderObjects(false,false);
    	foreach ($folders as $key=> $folder) {
    		// debugLog(array2string($folder));
    		debugLog ("DisplayName: " . $folder->shortDisplayName);
    		debugLog ("FolderName : " . $folder->folderName);
    		debugLog ("Delimiter  : " . $folder->delimiter);
    		$buf = explode ($folder->delimiter, $folder->folderName);
    		$folderentry["parent"] = $parent = 0;
    		if (count ($buf) > 1) {
    			array_pop ($buf);
    			$parent = implode ($folder->delimiter, $buf);
    			if (array_key_exists ($parent,$folders)) {
    				$folderentry["parent"] =  $this->backend->createID ("mail", $folder->parent, 0);
    				debugLog ("Parent : " . $parent);
    			};
    		};


    		$folderentry["mod"] =  $folder->shortDisplayName;
    		$folderentry["id"] = $this->backend->createID ("mail", $folder->folderName, 0);

    		$folderlist[] = $folderentry;
    	}
    	$mail->closeConnection();

  		// TODO : other apps


		return folderlist;
	}

	function GetFolder($id) {
		debugLog('VCDir::GetFolder('.$id.')');
		if($id == "root") {

			$folder = new SyncFolder();

			$folder->serverid = $id;
			$folder->parentid = "0";
			$folder->displayname = "Contacts";
			$folder->type = SYNC_FOLDER_TYPE_CONTACT;

			return $folder;
		}
		elseif ($id == "xtest") {

				$folder = new SyncFolder();

			$folder->serverid = $id;
			$folder->parentid = "0";
			$folder->displayname = "eGW Kalender";
			$folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;

				return $folder;


		}
	  	else
	  	{
	  	 return false;
	  	}
	}

	function StatFolder($id) {
		debugLog('VCDir::StatFolder('.$id.')');
		$folder = $this->GetFolder($id);

		$stat = array();
		$stat["type"] = "change";
		$stat["id"] = $id;
		$stat["parent"] = $folder->parentid;
		$stat["mod"] = $folder->displayname;

		return $stat;
	}




/*		function GetFolderList() {
			$folderlist = array();

			$folder = new SyncFolder();
			$folder->serverid = "myfolder";
	  $folder->parentid = "0";
	  $folder->displayname = "Contacts";
	  $folder->type = SYNC_FOLDER_TYPE_CONTACT;

			$folderlist[] = $folder;

			debugLog(print_r($folderlist,true));
			return $folderlist;


		}



		function GetFolder($id) {
		debugLog('VCDir::GetFolder('.$id.')');
		if($id == "root") {
			$folder = new SyncFolder();
			$folder->serverid = $id;
			$folder->parentid = "0";
			$folder->displayname = "Contacts";
			$folder->type = SYNC_FOLDER_TYPE_CONTACT;

			return $folder;
		} else return false;
	}

*/

	function GetState() {
		debugLog(__METHOD__);
		return serialize($this->_syncstate);
	}

	function GetChangeCount() {
	 	debugLog(__METHOD__);
	 	return count($this->_changes);
	}

	function Synchronize() {
		debugLog(__METHOD__. " found ". count($this->_changes) . " changes ");

		if($this->_folderid == false) {
			if($this->_step < count($this->_changes)) {
				$change = $this->_changes[$this->_step];
								debugLog ("SYNC NO FOLDERID " . $change["type"]);
				switch($change["type"]) {
					case "change":
						$folder = $this->GetFolder($change["id"]);
						$stat = $this->StatFolder($change["id"]);

						if(!$folder)
							return;

						if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportFolderChange($folder))
							// $this->updateState("change", $stat);
						break;
					case "delete":
						if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportFolderDeletion($change["id"]))
							// $this->updateState("delete", $change);
						break;
				}

				$this->_step++;

				$progress = array();
				$progress["steps"] = count($this->_changes);
				$progress["progress"] = $this->_step;

				return $progress;
			} else {
				return false;
			}
		}
		else {
			if($this->_step < count($this->_changes)) {
				$change = $this->_changes[$this->_step];
		   			debugLog ("SYNC FOLDERID " . $change["type"]);

				switch($change["type"]) {
					case "flags":
					case "olflags":
					case "change":
						$truncsize = $this->getTruncSize($this->_truncation);

						// Note: because 'parseMessage' and 'statMessage' are two seperate
						// calls, we have a chance that the message has changed between both
						// calls. This may cause our algorithm to 'double see' changes.

						$stat = $this->StatMessage($this->_folderid, $change["id"]);
						$message = $this->GetMessage($this->_folderid, $change["id"], $truncsize,(isset($this->_bodypreference) ? $this->_bodypreference : false));

						// copy the flag to the message
						$message->flags = (isset($change["flags"])) ? $change["flags"] : 0;

						if($stat && $message) {
							if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageChange($change["id"], $message) == true) {
								if ($change["type"] == "change") $this->updateState("change", $stat);
							if ($change["type"] == "flags") $this->updateState("flags", $change);
							if ($change["type"] == "olflags") $this->updateState("olflags", $change);
													}
						}
						break;
					case "delete":
						if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageDeletion($change["id"]) == true)
							$this->updateState("delete", $change);
						break;
/*					case "flags":
						if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageReadFlag($change["id"], $change["flags"]) == true)
							$this->updateState("flags", $change);
						break;
					case "olflags":
						$truncsize = $this->getTruncSize($this->_truncation);

						// Note: because 'parseMessage' and 'statMessage' are two seperate
						// calls, we have a chance that the message has changed between both
						// calls. This may cause our algorithm to 'double see' changes.

						$stat = $this->_backend->StatMessage($this->_folderid, $change["id"]);
						$message = $this->_backend->GetMessage($this->_folderid, $change["id"], $truncsize,(isset($this->_bodypreference) ? $this->_bodypreference : false));

						// copy the flag to the message
						$message->flags = (isset($change["flags"])) ? $change["flags"] : 0;

						if($stat && $message) {
							if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageChange($change["id"], $message) == true)
							$this->updateState("olflags", $change);
						}
						break;
*/					case "move":
						if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageMove($change["id"], $change["parent"]) == true)
							$this->updateState("move", $change);
						break;
				}

				$this->_step++;

				$progress = array();
				$progress["steps"] = count($this->_changes);
				$progress["progress"] = $this->_step;

				return $progress;
			} else {
				return false;
			}
		}

	}

	// ----------------------------------------------------------------------------------------------

	function _getCutOffDate($restrict) {
		switch($restrict) {
			case SYNC_FILTERTYPE_1DAY:
				$back = 60 * 60 * 24;
				break;
			case SYNC_FILTERTYPE_3DAYS:
				$back = 60 * 60 * 24 * 3;
				break;
			case SYNC_FILTERTYPE_1WEEK:
				$back = 60 * 60 * 24 * 7;
				break;
			case SYNC_FILTERTYPE_2WEEKS:
				$back = 60 * 60 * 24 * 14;
				break;
			case SYNC_FILTERTYPE_1MONTH:
				$back = 60 * 60 * 24 * 31;
				break;
			case SYNC_FILTERTYPE_3MONTHS:
				$back = 60 * 60 * 24 * 31 * 3;
				break;
			case SYNC_FILTERTYPE_6MONTHS:
				$back = 60 * 60 * 24 * 31 * 6;
				break;
			default:
				break;
		}

		if(isset($back)) {
			$date = time() - $back;
			return $date;
		} else
			return 0; // unlimited
	}

	function _getSMSRestriction($timestamp) {

	}

	function _getEmailRestriction($timestamp) {

	}

	function _getPropIDFromString($stringprop) {
	}

	// Create a MAPI restriction to use in the calendar which will
	// return all future calendar items, plus those since $timestamp
	function _getCalendarRestriction($timestamp) {
		// This is our viewing window

	}
}

