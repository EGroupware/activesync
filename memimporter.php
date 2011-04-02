<?php
/***********************************************
* File      :   memimporter.php
* Project   :   Z-Push
* Descr     :   Classes that collect changes
*
* Created   :   01.10.2007
*
* © Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

class ImportContentsChangesMem extends ImportContentsChanges {
    var $_changes;
    var $_deletions;

    function ImportContentsChangesMem() {
        $this->_changes = array();
        $this->_deletions = array();
        $this->_md5tosrvid = array();
    }
    
    function ImportMessageChange($id, $message) {
		if (isset($message->messageclass) &&
			strtolower($message->messageclass) == 'ipm.note.mobile.sms') {
//			debugLog(print_r($message,true));
			$md5msg = array('datereceived' 				=> (isset($message->datereceived) 			? $message->datereceived 				: ''),
							'importance' 				=> (isset($message->importance) 			? $message->importance 					: ''),
							'messageclass' 				=> (isset($message->messageclass) 			? $message->messageclass 				: ''),
							'to' 						=> (isset($message->to) 					? $message->to 							: ''),
							'cc' 						=> (isset($message->cc) 					? $message->cc 							: ''),
							'from' 						=> (isset($message->from) 					? $message->from 						: ''),
							'internetcpid' 				=> (isset($message->internetcpid) 			? $message->internetcpid 				: ''),
//							'conversationid' 			=> (isset($message->conversationid) 		? bin2hex($message->conversationid) 	: ''),
//							'conversationindex' 		=> (isset($message->conversationindex) 		? bin2hex($message->conversationindex) 	: ''),
							'body' 						=> (isset($message->airsyncbasebody->data)	? $message->airsyncbasebody->data 		: ''),
							'flagstatus' 				=> (isset($message->poommailflag->flagstatus) 		? $message->poommailflag->flagstatus 		: ''),
							'flagtype'					=> (isset($message->poommailflag->flagtype) 		? $message->poommailflag->flagtype 			: ''),
							'startdate'					=> (isset($message->poommailflag->startdate) 		? $message->poommailflag->startdate 		: ''),
							'utcstartdate'				=> (isset($message->poommailflag->utcstartdate) 	? $message->poommailflag->utcstartdate 		: ''),
							'duedate'					=> (isset($message->poommailflag->duedate) 			? $message->poommailflag->duedate 			: ''),
							'utcduedate'				=> (isset($message->poommailflag->utcduedate) 		? $message->poommailflag->utcduedate 		: ''),
							'datecomplete'				=> (isset($message->poommailflag->datecompleted) 	? $message->poommailflag->datecompleted 	: ''),
							'reminderset'			 	=> (isset($message->poommailflag->reminderset) 		? $message->poommailflag->reminderset 		: ''),
							'subject'					=> (isset($message->poommailflag->subject) 			? $message->poommailflag->subject 			: ''),
							'ordinaldate'				=> (isset($message->poommailflag->ordinaldate) 		? $message->poommailflag->ordinaldate 		: ''),
							'subordinaldate'			=> (isset($message->poommailflag->subordinaldate) 	? $message->poommailflag->subordinaldate 	: ''),
							'completetime'				=> (isset($message->poommailflag->completetime) 	? $message->poommailflag->completetime 		: ''),
							);
			$this->_md5tosrvid[md5(serialize($md5msg))] = array('serverid' 			=> $id,
																'conversationid' 	=> $message->conversationid,
																'conversationindex' => $message->conversationindex,
																);
		}
        $this->_changes[] = $id; 
        return true;
    }

    function ImportMessageDeletion($id) { 
        $this->_deletions[] = $id;
        return true;
    }
    
    function ImportMessageReadFlag($message) { return true; }

    function ImportMessageMove($message) { return true; }

    function isChanged($id) {
        return in_array($id, $this->_changes);
    }
    
    function isDeleted($id) {
        return in_array($id, $this->_deletions);
    }

};

// This simply collects all changes so that they can be retrieved later, for
// statistics gathering for example
class ImportHierarchyChangesMem extends ImportHierarchyChanges {
    var $changed;
    var $deleted;
    var $count;
    
    function ImportHierarchyChangesMem() {
        $this->changed = array();
        $this->deleted = array();
        $this->count = 0;
        
        return true;
    }
    
    function ImportFolderChange($folder) {
        array_push($this->changed, $folder);
        
        $this->count++;

        return true;
    }

    function ImportFolderDeletion($id) {
        array_push($this->deleted, $id);
        
        $this->count++;
        
        return true;
    }
};

?>