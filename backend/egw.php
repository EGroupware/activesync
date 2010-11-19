<?php
/***********************************************
* File      :   egw.php
* Project   :   Z-Push/eGroupware
* Descr     :
*
* Created   :   01.11.2010
*
*
* This file is distributed under GPL v2.
*
************************************************/


// The is an improved version of mimeDecode from PEAR that correctly
// handles charsets and charset conversion
include_once('mime.php');
include_once('mimeDecode.php');
require_once('z_RFC822.php');
require_once ('../felamimail/inc/class.bofelamimail.inc.php');


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
    }







    // CHANGED dw2412 Support Protocol Version 12 (added bodypreference)
    function Config(&$importer, $mclass, $restrict, $syncstate, $flags, $truncation, $bodypreference) {


    		// die("XX");
    		$mail = new bofelamimail ();
    		$mail->openConnection(0,false);
    		error_log("mail_error :" .$mail->getErrorMessage);
    		$folders = $mail->getFolderObjects(false,false);
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
        debugLog('VCDir::GetFolderList()');
        $contacts = array();
        $folder = $this->StatFolder("root");
        $contacts[] = $folder;
        $folder = $this->StatFolder("xtest");
        $contacts[] = $folder;

        return $contacts;
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
/*                    case "flags":
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
*/                    case "move":
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

class BackendEGW
{
	var $egw_sessionID;

	var $_user;
    var $_devid;
    var $_protocolversion;

    var $hierarchyimporter;
    var $contentsimporter;
    var $exporter;

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

   		return true;
	}

    // called before closing connection
    function Logoff()
    {
    	if (!$GLOBALS['egw']->session->destroy($this->egw_sessionID,"")) {
    		debugLog ("nothing to destroy");
    	}
    	debugLog ("LOGOFF");
	}

	function Setup($user, $devid, $protocolversion)
	{
		$this->_protocolversion = protocolversion;
		$this->_user = $user;
		$this->_devid = $devid;
		return true;
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

    // Returns an array of SyncFolder types for the entire folder hierarchy
    // on the server (the array itself is flat, but refers to parents via the 'parent'
    // property)
    function GetHierarchy() {
    	debugLog ("XXXXXXXXXXXXX");
    	debugLog (__METHOD__);
    }

    // Called when a message has to be sent and the message needs to be saved to the 'sent items'
    // folder
    function SendMail($rfc822, $smartdata=array(), $protocolversion = false) {}


	/**
     * Checks if the sent policykey matches the latest policykey on the server
     *
 	 * @param string $policykey
     * @param string $devid
     *
 	 * @return status flag
     */
	function CheckPolicy($policykey, $devid)
	{
		return true;
	}


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
};
