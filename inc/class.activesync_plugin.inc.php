<?php
/**
 * EGroupware - eSync - ActiveSync plugin interfaces
 *
 * @link http://www.egroupware.org
 * @package esync
 * @author Ralf Becker <rb@egroupware.org>
 * @author EGroupware GmbH <info@egroupware.org>
 * @author Philip Herbert <philip@knauber.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

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
	 * @param $id of the folder to delete
	 * @param $parentid of the folder to delete
	 *
	 * @return
	 * @TODO check what is to be returned
	 *
	 */
	public function DeleteFolder($id, $parentid);

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
	 * @param activesync_backend $backend
	 */
	public function __construct(activesync_backend $backend);

	/**
	 *  This function is analogous to GetMessageList.
	 */
	public function GetFolderList();

	/**
	 * Return a changes array
	 *
	 * if changes occurr default diff engine computes the actual changes
	 *
	 * @param string $id
	 * @param string& $synckey call old syncstate, on return new syncstate
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
	 * @param $cutoffdate =NULL
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
	 * @param int $cutoffdate =null
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
