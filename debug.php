<?php
/***********************************************
* File      :   debug.php
* Project   :   Z-Push
* Descr     :   Debuging functions
*
* Created   :   01.10.2007
*
* � Zarafa Deutschland GmbH, www.zarafaserver.de
* This file is distributed under GPL v2.
* Consult LICENSE file for details
************************************************/

global $debugstr;

function debug($str) {
    global $debugstr;
    $debugstr .= "$str\n";
}

function getDebugInfo() {
    global $debugstr;

    return $debugstr;
}

function debugLog($message) {
    global $devid;

    // global log
    if ((@$fp = fopen(STATE_DIR . "/debug.txt","a"))) {
	@$date = strftime("%x %X");
	@fwrite($fp, "$date [". getmypid() ."] $message\n");
        @fclose($fp);
    }
    // logging by device
    if (isset($devid) && strlen($devid) > 0 &&
	($fn = STATE_DIR . "/". strtolower($devid). "/debug.txt") &&
	file_exists($fn)) {
    	@$fp = fopen($fn,"a");
    	@$date = strftime("%x %X");
	@fwrite($fp, "$date [". getmypid() ."] $message\n");
	@fclose($fp);
    }
}

function zarafa_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
    debugLog("$errfile:$errline $errstr ($errno)");
}

// E_STRICT in PHP 5.4 gives various strict warnings in working code, which can NOT be easy fixed in all use-cases :-(
// Only variables should be assigned by reference, eg. soetemplate::tree_walk()
// Declaration of <extended method> should be compatible with <parent method>, varios places where method parameters change
// --> switching it off for now, as it makes error-log unusable
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
set_error_handler("zarafa_error_handler",E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
