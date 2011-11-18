<?php
// Shaarli 0.0.29 beta - Shaare your links...
// The personal, minimalist, super-fast, no-database delicious clone. By sebsauvage.net
// http://sebsauvage.net/wiki/doku.php?id=php:shaarli
// Licence: http://www.opensource.org/licenses/zlib-license.php

// Requires: php 5.1.x
// (but autocomplete fields will only work if you have php 5.2.x)
// -----------------------------------------------------------------------------------------------
// User config:
$GLOBALS['config']['DATADIR'] = 'data'; // Data subdirectory
$GLOBALS['config']['CONFIG_FILE'] = $GLOBALS['config']['DATADIR'].'/config.php'; // Configuration file (user login/password)
$GLOBALS['config']['DATASTORE'] = $GLOBALS['config']['DATADIR'].'/datastore.php'; // Data storage file.
$GLOBALS['config']['LINKS_PER_PAGE'] = 20; // Default links per page.
$GLOBALS['config']['IPBANS_FILENAME'] = $GLOBALS['config']['DATADIR'].'/ipbans.php'; // File storage for failures and bans.
$GLOBALS['config']['BAN_AFTER'] = 4;        // Ban IP after this many failures.
$GLOBALS['config']['BAN_DURATION'] = 1800;  // Ban duration for IP address after login failures (in seconds) (1800 sec. = 30 minutes)
$GLOBALS['config']['OPEN_SHAARLI'] = false; // If true, anyone can add/edit/delete links without having to login
$GLOBALS['config']['HIDE_TIMESTAMPS'] = false; // If true, the moment when links were saved are not shown to users that are not logged in.
$GLOBALS['config']['ENABLE_THUMBNAILS'] = true; // Enable thumbnails in links.
$GLOBALS['config']['CACHEDIR'] = 'cache'; // Cache directory for thumbnails for SLOW services (like flickr)
$GLOBALS['config']['ENABLE_LOCALCACHE'] = true; // Enable Shaarli to store thumbnail in a local cache. Disable to reduce webspace usage.
$GLOBALS['config']['PUBSUBHUB_URL'] = ''; // PubSubHub support. Put an empty string to disable, or put your hub url here to enable.
                                          // Note: You must have publisher.php in the same directory as Shaarli index.php   
// -----------------------------------------------------------------------------------------------
// Program config (touch at your own risks !)
$GLOBALS['config']['UPDATECHECK_FILENAME'] = $GLOBALS['config']['DATADIR'].'/lastupdatecheck.txt'; // For updates check of Shaarli.
$GLOBALS['config']['UPDATECHECK_INTERVAL'] = 86400 ; // Updates check frequency for Shaarli. 86400 seconds=24 hours
ini_set('max_input_time','60');  // High execution time in case of problematic imports/exports.
ini_set('memory_limit', '128M');  // Try to set max upload file size and read (May not work on some hosts).
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');
define('PHPPREFIX','<?php /* '); // Prefix to encapsulate data in php code.
define('PHPSUFFIX',' */ ?>'); // Suffix to encapsulate data in php code.
$STARTTIME = microtime(true);  // Measure page execution time.
checkphpversion();
error_reporting(E_ALL^E_WARNING);  // See all error except warnings.
//error_reporting(-1); // See all errors (for debugging only)
ob_start();

// Optionnal config file.
if (is_file($GLOBALS['config']['DATADIR'].'/options.php')) require($GLOBALS['config']['DATADIR'].'/options.php');

// In case stupid admin has left magic_quotes enabled in php.ini:
if (get_magic_quotes_gpc()) 
{
    function stripslashes_deep($value) { $value = is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value); return $value; }
    $_POST = array_map('stripslashes_deep', $_POST);
    $_GET = array_map('stripslashes_deep', $_GET);
    $_COOKIE = array_map('stripslashes_deep', $_COOKIE);
}
// Prevent caching: (yes, it's ugly)
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
define('shaarli_version','0.0.29 beta');
if (!is_dir($GLOBALS['config']['DATADIR'])) { mkdir($GLOBALS['config']['DATADIR'],0705); chmod($GLOBALS['config']['DATADIR'],0705); }
if (!is_file($GLOBALS['config']['DATADIR'].'/.htaccess')) { file_put_contents($GLOBALS['config']['DATADIR'].'/.htaccess',"Allow from none\nDeny from all\n"); } // Protect data files.    
if ($GLOBALS['config']['ENABLE_LOCALCACHE'])
{
    if (!is_dir($GLOBALS['config']['CACHEDIR'])) { mkdir($GLOBALS['config']['CACHEDIR'],0705); chmod($GLOBALS['config']['CACHEDIR'],0705); }
    if (!is_file($GLOBALS['config']['CACHEDIR'].'/.htaccess')) { file_put_contents($GLOBALS['config']['CACHEDIR'].'/.htaccess',"Allow from none\nDeny from all\n"); } // Protect data files.    
}
if (!is_file($GLOBALS['config']['CONFIG_FILE'])) install();
require $GLOBALS['config']['CONFIG_FILE'];  // Read login/password hash into $GLOBALS.
// Small protection against dodgy config files:
if (empty($GLOBALS['title'])) $GLOBALS['title']='Shared links on '.htmlspecialchars(serverUrl().$_SERVER['SCRIPT_NAME']);
if (empty($GLOBALS['timezone'])) $GLOBALS['timezone']=date_default_timezone_get();
autoLocale(); // Sniff browser language and set date format accordingly.
header('Content-Type: text/html; charset=utf-8'); // We use UTF-8 for proper international characters handling.
$LINKSDB=false;

// Check php version 
function checkphpversion()
{
    if (version_compare(PHP_VERSION, '5.1.0') < 0)
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Your server supports php '.PHP_VERSION.'. Shaarli requires at last php 5.1.0, and thus cannot run. Sorry.';
        exit;
    }        
}

// Checks if an update is available for Shaarli.
// (at most once a day, and only for registered user.)
// Output: '' = no new version.
//         other= the available version.
function checkUpdate()
{
    if (!isLoggedIn()) return ''; // Do not check versions for visitors.
    
    // Get latest version number at most once a day.
    if (!is_file($GLOBALS['config']['UPDATECHECK_FILENAME']) || (filemtime($GLOBALS['config']['UPDATECHECK_FILENAME'])<time()-($GLOBALS['config']['UPDATECHECK_INTERVAL'])))
    {
        $version=shaarli_version;
        list($httpstatus,$headers,$data) = getHTTP('http://sebsauvage.net/files/shaarli_version.txt',2);
        if (strpos($httpstatus,'200 OK')!==false) $version=$data;
        // If failed, nevermind. We don't want to bother the user with that.  
        file_put_contents($GLOBALS['config']['UPDATECHECK_FILENAME'],$version); // touch file date
    }
    // Compare versions:
    $newestversion=file_get_contents($GLOBALS['config']['UPDATECHECK_FILENAME']);
    if (version_compare($newestversion,shaarli_version)==1) return $newestversion;
    return '';
}

// -----------------------------------------------------------------------------------------------
// Log to text file
function logm($message)
{
    $t = strval(date('Y/m/d_H:i:s')).' - '.$_SERVER["REMOTE_ADDR"].' - '.strval($message)."\n";
    file_put_contents($GLOBALS['config']['DATADIR'].'/log.txt',$t,FILE_APPEND);
}

/* Returns the small hash of a string
   eg. smallHash('20111006_131924') --> yZH23w
   Small hashes:
     - are unique (well, as unique as crc32, at last)
     - are always 6 characters long.
     - only use the following characters: a-z A-Z 0-9 - _ @
     - are NOT cryptographically secure (they CAN be forged)
   In Shaarli, they are used as a tinyurl-like link to individual entries.
*/  
function smallHash($text)
{
    $t = rtrim(base64_encode(hash('crc32',$text,true)),'=');
    $t = str_replace('+','-',$t); // Get rid of characters which need encoding in URLs.
    $t = str_replace('/','_',$t);
    $t = str_replace('=','@',$t);
    return $t;
}

// Function inspired from http://www.php.net/manual/en/function.preg-replace.php#85722
function text2clickable($url)
{
    $redir = empty($GLOBALS['redirector']) ? '' : $GLOBALS['redirector'];
    return preg_replace('!((?:https?|ftp)://\S+[[:alnum:]]/?)!si','<a href="'.$redir.'$1" rel="nofollow">$1</a>',$url);
}
// ------------------------------------------------------------------------------------------
// Sniff browser language to display dates in the right format automatically.
// (Note that is may not work on your server if the corresponding local is not installed.)
function autoLocale()
{     
    $loc='en_US'; // Default if browser does not send HTTP_ACCEPT_LANGUAGE
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) // eg. "fr,fr-fr;q=0.8,en;q=0.5,en-us;q=0.3"
    {   // (It's a bit crude, but it works very well. Prefered language is always presented first.)
        if (preg_match('/([a-z]{2}(-[a-z]{2})?)/i',$_SERVER['HTTP_ACCEPT_LANGUAGE'],$matches)) $loc=$matches[1];
    }
    setlocale(LC_TIME,$loc);  // LC_TIME = Set local for date/time format only.
}

// ------------------------------------------------------------------------------------------
// PubSubHub protocol support (if enabled)  [UNTESTED]
// (Source: http://aldarone.fr/les-flux-rss-shaarli-et-pubsubhubbub/ )
if (!empty($GLOBALS['config']['PUBSUBHUB_URL'])) include './publisher.php';
function pubsubhub()
{
    if (!empty($GLOBALS['config']['PUBSUBHUB_URL']))
    {
       $p = new Publisher($GLOBALS['config']['PUBSUBHUB_URL']);
       $topic_url = array (
                       serverUrl().$_SERVER['SCRIPT_NAME'].'?do=atom',
                       serverUrl().$_SERVER['SCRIPT_NAME'].'?do=rss'
                    );
       $p->publish_update($topic_url);
    }
}

// ------------------------------------------------------------------------------------------
// Session management
define('INACTIVITY_TIMEOUT',3600); // (in seconds). If the user does not access any page within this time, his/her session is considered expired.
ini_set('session.use_cookies', 1);       // Use cookies to store session.
ini_set('session.use_only_cookies', 1);  // Force cookies for session (phpsessionID forbidden in URL)
ini_set('session.use_trans_sid', false); // Prevent php to use sessionID in URL if cookies are disabled.
session_name('shaarli');
session_start();

// Returns the IP address of the client (Used to prevent session cookie hijacking.)
function allIPs()
{
    $ip = $_SERVER["REMOTE_ADDR"];
    // Then we use more HTTP headers to prevent session hijacking from users behind the same proxy.
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { $ip=$ip.'_'.$_SERVER['HTTP_X_FORWARDED_FOR']; }
    if (isset($_SERVER['HTTP_CLIENT_IP'])) { $ip=$ip.'_'.$_SERVER['HTTP_CLIENT_IP']; }
    return $ip;
}

// Check that user/password is correct.
function check_auth($login,$password) 
{
    $hash = sha1($password.$login.$GLOBALS['salt']);
    if ($login==$GLOBALS['login'] && $hash==$GLOBALS['hash'])
    {   // Login/password is correct.
        $_SESSION['uid'] = sha1(uniqid('',true).'_'.mt_rand()); // generate unique random number (different than phpsessionid)
        $_SESSION['ip']=allIPs();                // We store IP address(es) of the client to make sure session is not hijacked.
        $_SESSION['username']=$login;
        $_SESSION['expires_on']=time()+INACTIVITY_TIMEOUT;  // Set session expiration.
        logm('Login successful');
        return True;
    }
    logm('Login failed for user '.$login);
    return False;
}

// Returns true if the user is logged in.
function isLoggedIn()
{ 
    if ($GLOBALS['config']['OPEN_SHAARLI']) return true; 
    
    // If session does not exist on server side, or IP address has changed, or session has expired, logout.
    if (empty($_SESSION['uid']) || $_SESSION['ip']!=allIPs() || time()>=$_SESSION['expires_on'])
    {
        logout();
        return false;
    }
    if (!empty($_SESSION['longlastingsession']))  $_SESSION['expires_on']=time()+$_SESSION['longlastingsession']; // In case of "Stay signed in" checked.
    else $_SESSION['expires_on']=time()+INACTIVITY_TIMEOUT; // Standard session expiration date.
    
    return true;
}

// Force logout.
function logout() { if (isset($_SESSION)) { unset($_SESSION['uid']); unset($_SESSION['ip']); unset($_SESSION['username']);}  }


// ------------------------------------------------------------------------------------------
// Brute force protection system
// Several consecutive failed logins will ban the IP address for 30 minutes.
if (!is_file($GLOBALS['config']['IPBANS_FILENAME'])) file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], "<?php\n\$GLOBALS['IPBANS']=".var_export(array('FAILURES'=>array(),'BANS'=>array()),true).";\n?>");
include $GLOBALS['config']['IPBANS_FILENAME'];
// Signal a failed login. Will ban the IP if too many failures:
function ban_loginFailed()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    if (!isset($gb['FAILURES'][$ip])) $gb['FAILURES'][$ip]=0;
    $gb['FAILURES'][$ip]++;
    if ($gb['FAILURES'][$ip]>($GLOBALS['config']['BAN_AFTER']-1))
    {
        $gb['BANS'][$ip]=time()+$GLOBALS['config']['BAN_DURATION'];
        logm('IP address banned from login');
    }
    $GLOBALS['IPBANS'] = $gb;
    file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
}

// Signals a successful login. Resets failed login counter.
function ban_loginOk()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    unset($gb['FAILURES'][$ip]); unset($gb['BANS'][$ip]);
    $GLOBALS['IPBANS'] = $gb;
    file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
}

// Checks if the user CAN login. If 'true', the user can try to login.
function ban_canLogin()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    if (isset($gb['BANS'][$ip]))
    {
        // User is banned. Check if the ban has expired:
        if ($gb['BANS'][$ip]<=time())  
        {   // Ban expired, user can try to login again.
            logm('Ban lifted.');
            unset($gb['FAILURES'][$ip]); unset($gb['BANS'][$ip]);
            file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
            return true; // Ban has expired, user can login.
        }
        return false; // User is banned.
    }
    return true; // User is not banned.
}

// ------------------------------------------------------------------------------------------
// Process login form: Check if login/password is correct.
if (isset($_POST['login']))
{
    if (!ban_canLogin()) die('I said: NO. You are banned for the moment. Go away.');
    if (isset($_POST['password']) && tokenOk($_POST['token']) && (check_auth($_POST['login'], $_POST['password'])))
    {   // Login/password is ok.
        ban_loginOk();  
        // If user wants to keep the session cookie even after the browser closes:
        if (!empty($_POST['longlastingsession']))
        {
            $_SESSION['longlastingsession']=31536000;  // (31536000 seconds = 1 year)
            $_SESSION['expires_on']=time()+$_SESSION['longlastingsession'];  // Set session expiration on server-side.
            session_set_cookie_params($_SESSION['longlastingsession']); // Set session cookie expiration on client side 
            session_regenerate_id(true);  // Send cookie with new expiration date to browser.
        }
        else // Standard session expiration (=when browser closes)
        {
            session_set_cookie_params(0); // 0 means "When browser closes"
            session_regenerate_id(true); 
        }
        // Optional redirect after login:
        if (isset($_GET['post'])) { header('Location: ?post='.urlencode($_GET['post']).(!empty($_GET['title'])?'&title='.urlencode($_GET['title']):'').(!empty($_GET['source'])?'&source='.urlencode($_GET['source']):'')); exit; }
        if (isset($_POST['returnurl']))
        { 
            if (endsWith($_POST['returnurl'],'?do=login')) { header('Location: ?'); exit; } // Prevent loops over login screen.
            header('Location: '.$_POST['returnurl']); exit; 
        }
        header('Location: ?'); exit;
    }
    else
    {
        ban_loginFailed(); 
        echo '<script language="JavaScript">alert("Wrong login/password.");document.location=\'?do=login\';</script>'; // Redirect to login screen.
        exit;   
    }
} 

// ------------------------------------------------------------------------------------------
// Misc utility functions:

// Returns the server URL (including port and http/https), without path.
// eg. "http://myserver.com:8080"
// You can append $_SERVER['SCRIPT_NAME'] to get the current script URL.
function serverUrl()
{
        $serverport = ($_SERVER["SERVER_PORT"]!='80' ? ':'.$_SERVER["SERVER_PORT"] : ''); 
        return 'http'.(!empty($_SERVER['HTTPS'])?'s':'').'://'.$_SERVER["SERVER_NAME"].$serverport;
}

// Convert post_max_size/upload_max_filesize (eg.'16M') parameters to bytes.
function return_bytes($val) 
{
    $val = trim($val); $last=strtolower($val[strlen($val)-1]);
    switch($last) 
    {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

// Try to determine max file size for uploads (POST).
// Returns an integer (in bytes)
function getMaxFileSize()
{
    $size1 = return_bytes(ini_get('post_max_size'));
    $size2 = return_bytes(ini_get('upload_max_filesize'));
    // Return the smaller of two:
    $maxsize = min($size1,$size2);
    // FIXME: Then convert back to readable notations ? (eg. 2M instead of 2000000)
    return $maxsize;
}

// Tells if a string start with a substring or not.
function startsWith($haystack,$needle,$case=true)
{
    if($case){return (strcmp(substr($haystack, 0, strlen($needle)),$needle)===0);}
    return (strcasecmp(substr($haystack, 0, strlen($needle)),$needle)===0);
}

// Tells if a string ends with a substring or not.
function endsWith($haystack,$needle,$case=true)
{
    if($case){return (strcmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);}
    return (strcasecmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a timestamp (Unix epoch)
    (used to build the ADD_DATE attribute in Netscape-bookmarks file)
    PS: I could have used strptime(), but it does not exist on Windows. I'm too kind. */
function linkdate2timestamp($linkdate)
{
    $Y=$M=$D=$h=$m=$s=0;
    $r = sscanf($linkdate,'%4d%2d%2d_%2d%2d%2d',$Y,$M,$D,$h,$m,$s);
    return mktime($h,$m,$s,$M,$D,$Y);
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a RFC822 date.
    (used to build the pubDate attribute in RSS feed.)  */
function linkdate2rfc822($linkdate)
{
    return date('r',linkdate2timestamp($linkdate)); // 'r' is for RFC822 date format.
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a ISO 8601 date.
    (used to build the updated tags in ATOM feed.)  */
function linkdate2iso8601($linkdate)
{
    return date('c',linkdate2timestamp($linkdate)); // 'c' is for ISO 8601 date format.
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a localized date format.
    (used to display link date on screen)
    The date format is automatically chosen according to locale/languages sniffed from browser headers (see autoLocale()). */
function linkdate2locale($linkdate)
{
    return utf8_encode(strftime('%c',linkdate2timestamp($linkdate))); // %c is for automatic date format according to locale.
    // Note that if you use a local which is not installed on your webserver,
    // the date will not be displayed in the chosen locale, but probably in US notation.
}

// Parse HTTP response headers and return an associative array.
function http_parse_headers_shaarli( $headers ) 
{
    $res=array();
    foreach($headers as $header)
    {
        $i = strpos($header,': ');
        if ($i!==false)
        {
            $key=substr($header,0,$i);
            $value=substr($header,$i+2,strlen($header)-$i-2);
            $res[$key]=$value;
        }
    }
    return $res;
}

/* GET an URL.
   Input: $url : url to get (http://...)
          $timeout : Network timeout (will wait this many seconds for an anwser before giving up).
   Output: An array.  [0] = HTTP status message (eg. "HTTP/1.1 200 OK") or error message
                      [1] = associative array containing HTTP response headers (eg. echo getHTTP($url)[1]['Content-Type'])
                      [2] = data
    Example: list($httpstatus,$headers,$data) = getHTTP('http://sebauvage.net/');
             if (strpos($httpstatus,'200 OK')!==false)
                 echo 'Data type: '.htmlspecialchars($headers['Content-Type']);
             else
                 echo 'There was an error: '.htmlspecialchars($httpstatus)
*/
function getHTTP($url,$timeout=30)
{
    try
    {
        $options = array('http'=>array('method'=>'GET','timeout' => $timeout)); // Force network timeout
        $context = stream_context_create($options);
        $data=file_get_contents($url,false,$context,-1, 4000000); // We download at most 4 Mb from source.
        if (!$data) { $lasterror=error_get_last();  return array($lasterror['message'],array(),''); }
        $httpStatus=$http_response_header[0]; // eg. "HTTP/1.1 200 OK"
        $responseHeaders=http_parse_headers_shaarli($http_response_header);
        return array($httpStatus,$responseHeaders,$data);
    }
    catch (Exception $e)  // getHTTP *can* fail silentely (we don't care if the title cannot be fetched)
    {
        return array($e->getMessage(),'','');
    }
}

// Extract title from an HTML document.
// (Returns an empty string if not found.)
function html_extract_title($html) 
{
  return preg_match('!<title>(.*?)</title>!is', $html, $matches) ? trim(str_replace("\n",' ', $matches[1])) : '' ;   
}

// ------------------------------------------------------------------------------------------
// Token management for XSRF protection
// Token should be used in any form which acts on data (create,update,delete,import...).
if (!isset($_SESSION['tokens'])) $_SESSION['tokens']=array();  // Token are attached to the session.

// Returns a token.
function getToken()
{
    $rnd = sha1(uniqid('',true).'_'.mt_rand());  // We generate a random string.
    $_SESSION['tokens'][$rnd]=1;  // Store it on the server side.
    return $rnd;    
}

// Tells if a token is ok. Using this function will destroy the token.
// true=token is ok. 
function tokenOk($token)
{
    if (isset($_SESSION['tokens'][$token]))
    {
        unset($_SESSION['tokens'][$token]); // Token is used: destroy it.
        return true; // Token is ok.
    }
    return false; // Wrong token, or already used.
}

// ------------------------------------------------------------------------------------------
/* Data storage for links.
   This object behaves like an associative array.
   Example:
      $mylinks = new linkdb();
      echo $mylinks['20110826_161819']['title'];
      foreach($mylinks as $link)
         echo $link['title'].' at url '.$link['url'].' ; description:'.$link['description'];
   
   We implement 3 interfaces:
     - ArrayAccess so that this object behaves like an associative array.
     - Iterator so that this object can be used in foreach() loops.
     - Countable interface so that we can do a count() on this object.
*/
class linkdb implements Iterator, Countable, ArrayAccess

{
    private $links; // List of links (associative array. Key=linkdate (eg. "20110823_124546"), value= associative array (keys:title,description...)
    private $urls;  // List of all recorded URLs (key=url, value=linkdate) for fast reserve search (url-->linkdate)
    private $keys;  // List of linkdate keys (for the Iterator interface implementation)
    private $position; // Position in the $this->keys array. (for the Iterator interface implementation.)
    private $loggedin; // Is the used logged in ? (used to filter private links)

    // Constructor:
    function __construct($isLoggedIn)
    // Input : $isLoggedIn : is the used logged in ?
    {
        $this->loggedin = $isLoggedIn;
        $this->checkdb(); // Make sure data file exists.
        $this->readdb();  // Then read it.
    } 
    
    // ---- Countable interface implementation
    public function count() { return count($this->links); }

    // ---- ArrayAccess interface implementation
    public function offsetSet($offset, $value)
    {
        if (!$this->loggedin) die('You are not authorized to add a link.');
        if (empty($value['linkdate']) || empty($value['url'])) die('Internal Error: A link should always have a linkdate and url.');
        if (empty($offset)) die('You must specify a key.');
        $this->links[$offset] = $value;
        $this->urls[$value['url']]=$offset;    
    }
    public function offsetExists($offset) { return array_key_exists($offset,$this->links); }
    public function offsetUnset($offset)
    { 
        if (!$this->loggedin) die('You are not authorized to delete a link.');
        $url = $this->links[$offset]['url']; unset($this->urls[$url]);    
        unset($this->links[$offset]); 
    }
    public function offsetGet($offset) { return isset($this->links[$offset]) ? $this->links[$offset] : null; }
    
    // ---- Iterator interface implementation
    function rewind() { $this->keys=array_keys($this->links); rsort($this->keys); $this->position=0; } // Start over for iteration, ordered by date (latest first).
    function key() { return $this->keys[$this->position]; } // current key
    function current() { return $this->links[$this->keys[$this->position]]; } // current value
    function next() { ++$this->position; } // go to next item
    function valid() { return isset($this->keys[$this->position]); }    // Check if current position is valid.

    // ---- Misc methods
    private function checkdb() // Check if db directory and file exists.
    {
        if (!file_exists($GLOBALS['config']['DATASTORE'])) // Create a dummy database for example.
        {
             $this->links = array();
             $link = array('title'=>'Shaarli - sebsauvage.net','url'=>'http://sebsauvage.net/wiki/doku.php?id=php:shaarli','description'=>'Welcome to Shaarli ! This is a bookmark. To edit or delete me, you must first login.','private'=>0,'linkdate'=>'20110914_190000','tags'=>'opensource software');
             $this->links[$link['linkdate']] = $link;
             $link = array('title'=>'My secret stuff... - Pastebin.com','url'=>'http://pastebin.com/smCEEeSn','description'=>'SShhhh!!  I\'m a private link only YOU can see. You can delete me too.','private'=>1,'linkdate'=>'20110914_074522','tags'=>'secretstuff');
             $this->links[$link['linkdate']] = $link;             
             file_put_contents($GLOBALS['config']['DATASTORE'], PHPPREFIX.base64_encode(gzdeflate(serialize($this->links))).PHPSUFFIX); // Write database to disk
        }    
    }
    
    // Read database from disk to memory
    private function readdb() 
    {
        // Read data
        $this->links=(file_exists($GLOBALS['config']['DATASTORE']) ? unserialize(gzinflate(base64_decode(substr(file_get_contents($GLOBALS['config']['DATASTORE']),strlen(PHPPREFIX),-strlen(PHPSUFFIX))))) : array() );
        // Note that gzinflate is faster than gzuncompress. See: http://www.php.net/manual/en/function.gzdeflate.php#96439
        
        // If user is not logged in, filter private links.
        if (!$this->loggedin)
        {
            $toremove=array();
            foreach($this->links as $link) { if ($link['private']!=0) $toremove[]=$link['linkdate']; }
            foreach($toremove as $linkdate) { unset($this->links[$linkdate]); }
        }
        
        // Keep the list of the mapping URLs-->linkdate up-to-date.
        $this->urls=array();
        foreach($this->links as $link) { $this->urls[$link['url']]=$link['linkdate']; }
    }

    // Save database from memory to disk.    
    public function savedb() 
    {
        if (!$this->loggedin) die('You are not authorized to change the database.');
        file_put_contents($GLOBALS['config']['DATASTORE'], PHPPREFIX.base64_encode(gzdeflate(serialize($this->links))).PHPSUFFIX);
    }
    
    // Returns the link for a given URL (if it exists). false it does not exist.
    public function getLinkFromUrl($url) 
    {
        if (isset($this->urls[$url])) return $this->links[$this->urls[$url]];
        return false;
    }

    // Case insentitive search among links (in url, title and description). Returns filtered list of links.
    // eg. print_r($mydb->filterFulltext('hollandais'));
    public function filterFulltext($searchterms)  
    {
        // FIXME: explode(' ',$searchterms) and perform a AND search.
        // FIXME: accept double-quotes to search for a string "as is" ?
        $filtered=array();
        $s = strtolower($searchterms);
        foreach($this->links as $l)
        { 
            $found=   (strpos(strtolower($l['title']),$s)!==false)
                   || (strpos(strtolower($l['description']),$s)!==false)
                   || (strpos(strtolower($l['url']),$s)!==false)
                   || (strpos(strtolower($l['tags']),$s)!==false);
            if ($found) $filtered[$l['linkdate']] = $l;
        }
        krsort($filtered);
        return $filtered;
    }
    
    // Filter by tag.
    // You can specify one or more tags (tags can be separated by space or comma).
    // eg. print_r($mydb->filterTags('linux programming'));
    public function filterTags($tags,$casesensitive=false)
    {
        $t = str_replace(',',' ',($casesensitive?$tags:strtolower($tags)));
        $searchtags=explode(' ',$t);
        $filtered=array();
        foreach($this->links as $l)
        { 
            $linktags = explode(' ',($casesensitive?$l['tags']:strtolower($l['tags'])));
            if (count(array_intersect($linktags,$searchtags)) == count($searchtags))
                $filtered[$l['linkdate']] = $l;
        }
        krsort($filtered);
        return $filtered;
    }   
    
    // Filter by smallHash.
    // Only 1 article is returned.
    public function filterSmallHash($smallHash)
    {
        $filtered=array();
        foreach($this->links as $l)
        { 
            if ($smallHash==smallHash($l['linkdate'])) // Yes, this is ugly and slow
            {
                $filtered[$l['linkdate']] = $l;
                return $filtered;
            }
        }
        return $filtered;
    }     

    // Returns the list of all tags
    // Output: associative array key=tags, value=0
    public function allTags()
    {
        $tags=array();
        foreach($this->links as $link)
            foreach(explode(' ',$link['tags']) as $tag)
                if (!empty($tag)) $tags[$tag]=(empty($tags[$tag]) ? 1 : $tags[$tag]+1);
        arsort($tags); // Sort tags by usage (most used tag first)
        return $tags;
    }  
}

// ------------------------------------------------------------------------------------------
// Ouput the last 50 links in RSS 2.0 format.
function showRSS()
{
    global $LINKSDB;
    
    // Optionnaly filter the results:
    $linksToDisplay=array();
    if (!empty($_GET['searchterm'])) $linksToDisplay = $LINKSDB->filterFulltext($_GET['searchterm']);
    elseif (!empty($_GET['searchtags']))   $linksToDisplay = $LINKSDB->filterTags(trim($_GET['searchtags']));
    else $linksToDisplay = $LINKSDB;
        
    header('Content-Type: application/rss+xml; charset=utf-8');
    $pageaddr=htmlspecialchars(serverUrl().$_SERVER["SCRIPT_NAME"]);
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">';
    echo '<channel><title>'.htmlspecialchars($GLOBALS['title']).'</title><link>'.$pageaddr.'</link>';
    echo '<description>Shared links</description><language>en-en</language><copyright>'.$pageaddr.'</copyright>'."\n\n";
    if (!empty($GLOBALS['config']['PUBSUBHUB_URL']))
    {
        echo '<!-- PubSubHubbub Discovery -->';
        echo '<link rel="hub" href="'.htmlspecialchars($GLOBALS['config']['PUBSUBHUB_URL']).'" xmlns="http://www.w3.org/2005/Atom" />';
        echo '<link rel="self" href="'.htmlspecialchars($pageaddr).'?do=rss" xmlns="http://www.w3.org/2005/Atom" />';
        echo '<!-- End Of PubSubHubbub Discovery -->';
    }
    $i=0;
    $keys=array(); foreach($linksToDisplay as $key=>$value) { $keys[]=$key; }  // No, I can't use array_keys().
    while ($i<50 && $i<count($keys))
    {
        $link = $linksToDisplay[$keys[$i]];
        $rfc822date = linkdate2rfc822($link['linkdate']);
        $absurl = htmlspecialchars($link['url']);
        if (startsWith($absurl,'?')) $absurl=$pageaddr.$absurl;  // make permalink URL absolute
        echo '<item><title>'.htmlspecialchars($link['title']).'</title><guid>'.$absurl.'</guid><link>'.$absurl.'</link>';
        if (!$GLOBALS['config']['HIDE_TIMESTAMPS'] || isLoggedIn()) echo '<pubDate>'.htmlspecialchars($rfc822date)."</pubDate>\n";
        if ($link['tags']!='') // Adding tags to each RSS entry (as mentioned in RSS specification)
        {     
            foreach(explode(' ',$link['tags']) as $tag) { echo '<category domain="'.htmlspecialchars($pageaddr).'">'.htmlspecialchars($tag).'</category>'."\n"; }
        }          
        echo '<description><![CDATA['.nl2br(text2clickable(htmlspecialchars($link['description']))).']]></description>'."\n</item>\n";      
        $i++;
    }
    echo '</channel></rss>';
    exit;
}

// ------------------------------------------------------------------------------------------
// Ouput the last 50 links in ATOM format.
function showATOM()
{
    global $LINKSDB;
    
    // Optionnaly filter the results:
    $linksToDisplay=array();
    if (!empty($_GET['searchterm'])) $linksToDisplay = $LINKSDB->filterFulltext($_GET['searchterm']);
    elseif (!empty($_GET['searchtags']))   $linksToDisplay = $LINKSDB->filterTags(trim($_GET['searchtags']));
    else $linksToDisplay = $LINKSDB;
    
    header('Content-Type: application/atom+xml; charset=utf-8');
    $pageaddr=htmlspecialchars(serverUrl().$_SERVER["SCRIPT_NAME"]);
    $latestDate = '';
    $entries='';
    $i=0;
    $keys=array(); foreach($linksToDisplay as $key=>$value) { $keys[]=$key; }  // No, I can't use array_keys().
    while ($i<50 && $i<count($keys))
    {
        $link = $linksToDisplay[$keys[$i]];
        $iso8601date = linkdate2iso8601($link['linkdate']);
        $latestDate = max($latestDate,$iso8601date);
        $absurl = htmlspecialchars($link['url']);
        if (startsWith($absurl,'?')) $absurl=$pageaddr.$absurl;  // make permalink URL absolute        
        $entries.='<entry><title>'.htmlspecialchars($link['title']).'</title><link href="'.$absurl.'" /><id>'.$absurl.'</id>';
        if (!$GLOBALS['config']['HIDE_TIMESTAMPS'] || isLoggedIn()) $entries.='<updated>'.htmlspecialchars($iso8601date).'</updated>';
        $entries.='<summary>'.nl2br(text2clickable(htmlspecialchars($link['description'])))."</summary>\n";
        if ($link['tags']!='') // Adding tags to each ATOM entry (as mentioned in ATOM specification)
        {     
            foreach(explode(' ',$link['tags']) as $tag)
                { $entries.='<category scheme="'.htmlspecialchars($pageaddr,ENT_QUOTES).'" term="'.htmlspecialchars($tag,ENT_QUOTES).'" />'."\n"; }
        }  
        $entries.="</entry>\n";      
        $i++;
    }
    $feed='<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">';
    $feed.='<title>'.htmlspecialchars($GLOBALS['title']).'</title>';
    if (!$GLOBALS['config']['HIDE_TIMESTAMPS'] || isLoggedIn()) $feed.='<updated>'.htmlspecialchars($latestDate).'</updated>';
    $feed.='<link rel="self" href="'.htmlspecialchars($pageaddr).'" />';
    if (!empty($GLOBALS['config']['PUBSUBHUB_URL']))
    {
        $feed.='<!-- PubSubHubbub Discovery -->';
        $feed.='<link rel="hub" href="'.htmlspecialchars($GLOBALS['config']['PUBSUBHUB_URL']).'" />';
        $feed.='<!-- End Of PubSubHubbub Discovery -->';
    }
    $feed.='<author><uri>'.htmlspecialchars($pageaddr).'</uri></author>';
    $feed.='<id>'.htmlspecialchars($pageaddr).'</id>'."\n\n"; // Yes, I know I should use a real IRI (RFC3987), but the site URL will do.
    $feed.=$entries;
    $feed.='</feed>';
    echo $feed;
    exit;
}

// ------------------------------------------------------------------------------------------
// Render HTML page:
function renderPage()
{
    global $STARTTIME;
    global $LINKSDB;
    
    // Well... rendering the page would be 100x better with the excellent Smarty, but I don't want to tie this minimalist project to 100+ files.
    // So I use a custom templating system.
    
    // -------- Display login form.
    if (startswith($_SERVER["QUERY_STRING"],'do=login'))
    {
        if ($GLOBALS['config']['OPEN_SHAARLI']) { header('Location: ?'); exit; }  // No need to login for open Shaarli
        if (!ban_canLogin())
        { 
            $loginform='<div id="headerform">You have been banned from login after too many failed attempts. Try later.</div>';
            $data = array('pageheader'=>$loginform,'body'=>'','onload'=>''); 
            templatePage($data);
            exit;
        }
        $returnurl_html = (isset($_SERVER['HTTP_REFERER']) ? '<input type="hidden" name="returnurl" value="'.htmlspecialchars($_SERVER['HTTP_REFERER']).'">' : '');        
        $loginform='<div id="headerform"><form method="post" name="loginform">Login: <input type="text" name="login">&nbsp;&nbsp;&nbsp;Password : <input type="password" name="password"> <input type="submit" value="Login" class="bigbutton"><br>';
        $loginform.='<input style="margin:10 0 0 40;" type="checkbox" name="longlastingsession" id="longlastingsession"><label for="longlastingsession">&nbsp;Stay signed in (Do not check on public computers)</label><input type="hidden" name="token" value="'.getToken().'">'.$returnurl_html.'</form></div>';
        $onload = 'onload="document.loginform.login.focus();"';
        $data = array('pageheader'=>$loginform,'body'=>'','onload'=>$onload); 
        templatePage($data);
        exit;
    }
    // -------- User wants to logout.
    if (startswith($_SERVER["QUERY_STRING"],'do=logout'))
    { 
        invalidateCaches(); 
        logout(); 
        header('Location: ?'); 
        exit; 
    }  

    // -------- Picture wall
    if (startswith($_SERVER["QUERY_STRING"],'do=picwall'))
    { 
        // Optionnaly filter the results:
        $linksToDisplay=array();
        if (!empty($_GET['searchterm'])) $linksToDisplay = $LINKSDB->filterFulltext($_GET['searchterm']);
        elseif (!empty($_GET['searchtags']))   $linksToDisplay = $LINKSDB->filterTags(trim($_GET['searchtags']));
        else $linksToDisplay = $LINKSDB;
        $body='';
        
        foreach($linksToDisplay as $link)
        {
        	$href='?'.htmlspecialchars(smallhash($link['linkdate']),ENT_QUOTES);
            $thumb=thumbnail($link['url'],$href);
            if ($thumb!='')
            {
                $body.='<div class="picwall_pictureframe">'.$thumb.'<a href="'.$href.'"><span class="info">'.htmlspecialchars($link['title']).'</span></a></div>';
                
            }
        }
        $body = '<center><div id="picwall_container">'.$body.'<hr style="width:0;height:0;clear:both;"></div></center>';
        $data = array('pageheader'=>'<br>&nbsp;','body'=>$body,'onload'=>''); 
        templatePage($data);
        exit;        
        
    }      

    // -------- Tag cloud
    if (startswith($_SERVER["QUERY_STRING"],'do=tagcloud'))
    { 
        $tags= $LINKSDB->allTags();
        // We sort tags alphabetically, then choose a font size according to count.
        // First, find max value.
        $maxcount=0; foreach($tags as $key=>$value) $maxcount=max($maxcount,$value);
        ksort($tags);
        $cloud='';
        foreach($tags as $key=>$value)
        {
            $size = max(40*$value/$maxcount,8); // Minimum size 8.
            $colorvalue = 128-ceil(127*$value/$maxcount);
            $color='rgb('.$colorvalue.','.$colorvalue.','.$colorvalue.')';
            $cloud.= '<span style="color:#99f; font-size:9pt; padding-left:5px; padding-right:2px;">'.$value.'</span><a href="?searchtags='.htmlspecialchars($key).'" style="font-size:'.$size.'pt; font-weight:bold; color:'.$color.';">'.htmlspecialchars($key).'</a> ';
        }
        $cloud='<div id="cloudtag">'.$cloud.'</div>';
        $data = array('pageheader'=>'','body'=>$cloud,'onload'=>''); 
        templatePage($data);
        exit;
    }     
    
    // -------- User clicks on a tag in a link: The tag is added to the list of searched tags (searchtags=...)
    if (isset($_GET['addtag']))
    {
        // Get previous URL (http_referer) and add the tag to the searchtags parameters in query.
        if (empty($_SERVER['HTTP_REFERER'])) { header('Location: ?searchtags='.urlencode($_GET['addtag'])); exit; } // In case browser does not send HTTP_REFERER
        parse_str(parse_url($_SERVER['HTTP_REFERER'],PHP_URL_QUERY), $params);
        $params['searchtags'] = (empty($params['searchtags']) ?  trim($_GET['addtag']) : trim($params['searchtags']).' '.urlencode(trim($_GET['addtag'])));
        unset($params['page']); // We also remove page (keeping the same page has no sense, since the results are different)
        header('Location: ?'.http_build_query($params));
        exit;
    }

    // -------- User clicks on a tag in result count: Remove the tag from the list of searched tags (searchtags=...)
    if (isset($_GET['removetag']))
    {
        // Get previous URL (http_referer) and remove the tag from the searchtags parameters in query.
        if (empty($_SERVER['HTTP_REFERER'])) { header('Location: ?'); exit; } // In case browser does not send HTTP_REFERER
        parse_str(parse_url($_SERVER['HTTP_REFERER'],PHP_URL_QUERY), $params);
        if (isset($params['searchtags']))
        {
            $tags = explode(' ',$params['searchtags']);
            $tags=array_diff($tags, array($_GET['removetag'])); // Remove value from array $tags.
            if (count($tags)==0) unset($params['searchtags']); else $params['searchtags'] = implode(' ',$tags);
            unset($params['page']); // We also remove page (keeping the same page has no sense, since the results are different)
        }
        header('Location: ?'.http_build_query($params));
        exit;
    }    
    
    // -------- User wants to change the number of links per page (linksperpage=...)
    if (isset($_GET['linksperpage']))
    {
        if (is_numeric($_GET['linksperpage'])) { $_SESSION['LINKS_PER_PAGE']=abs(intval($_GET['linksperpage'])); }
        header('Location: '.(empty($_SERVER['HTTP_REFERER'])?'?':$_SERVER['HTTP_REFERER']));
        exit;
    }
    
    
    // -------- Handle other actions allowed for non-logged in users:
    if (!isLoggedIn())
    {
        // User tries to post new link but is not loggedin:
        // Show login screen, then redirect to ?post=...
        if (isset($_GET['post'])) 
        {
            header('Location: ?do=login&post='.urlencode($_GET['post']).(!empty($_GET['title'])?'&title='.urlencode($_GET['title']):'').(!empty($_GET['source'])?'&source='.urlencode($_GET['source']):'')); // Redirect to login page, then back to post link.
            exit;
        }
        
        // Show search form and display list of links.
        $searchform=<<<HTML
<div id="headerform" style="width:100%; white-space:nowrap;";>
    <form method="GET" class="searchform" name="searchform" style="display:inline;"><input type="text" name="searchterm" style="width:30%" value=""> <input type="submit" value="Search" class="bigbutton"></form>
    <form method="GET" class="tagfilter" name="tagfilter" style="display:inline;margin-left:24px;"><input type="text" name="searchtags" id="searchtags" style="width:10%" value=""> <input type="submit" value="Filter by tag" class="bigbutton"></form>
</div>
HTML;
        $data = array('pageheader'=>$searchform,'body'=>templateLinkList(),'onload'=>''); 
        templatePage($data);
        exit; // Never remove this one ! All operations below are reserved for logged in user.
    }
    
    // -------- All other functions are reserved for the registered user:
    
    // -------- Display the Tools menu if requested (import/export/bookmarklet...)
    if (startswith($_SERVER["QUERY_STRING"],'do=tools'))
    {
        $pageabsaddr=serverUrl().$_SERVER["SCRIPT_NAME"]; // Why doesn't php have a built-in function for that ?
        // The javascript code for the bookmarklet:
        $changepwd = ($GLOBALS['config']['OPEN_SHAARLI'] ? '' : '<a href="?do=changepasswd"><b>Change password</b></a> - Change your password.<br><br>' );
        $toolbar= <<<HTML
<div id="toolsdiv"><br>
    {$changepwd}        
    <a href="?do=configure"><b>Configure your Shaarli</b></a> - Change Title, timezone...<br><br>
    <a href="?do=changetag"><b>Rename/delete tags</b></a> - Rename or delete a tag in all links.<br><br>
    <a href="?do=import"><b>Import</b></a> - Import Netscape html bookmarks (as exported from Firefox, Chrome, Opera, delicious...)<br><br>
    <a href="?do=export"><b>Export</b></a> - Export Netscape html bookmarks (which can be imported in Firefox, Chrome, Opera, delicious...)<br><br>
    <a class="smallbutton"  onclick="alert('Drag this link to your bookmarks toolbar, or right-click it and choose Bookmark This Link...');return false;" href="javascript:javascript:(function(){var%20url%20=%20location.href;var%20title%20=%20document.title%20||%20url;window.open('{$pageabsaddr}?post='%20+%20encodeURIComponent(url)+'&amp;title='%20+%20encodeURIComponent(title)+'&amp;source=bookmarklet','_blank','menubar=no,height=400,width=608,toolbar=no,scrollbars=no,status=no');})();">Shaare link</a> - Drag this link to your bookmarks toolbar (or right-click it and choose Bookmark This Link....). Then click "Shaare link" button in any page you want to share.<br><br>
</div>
HTML;
        $data = array('pageheader'=>$toolbar,'body'=>'','onload'=>''); 
        templatePage($data);
        exit;
    }

    // -------- User wants to change his/her password.
    if (startswith($_SERVER["QUERY_STRING"],'do=changepasswd'))
    {
        if ($GLOBALS['config']['OPEN_SHAARLI']) die('You are not supposed to change a password on an Open Shaarli.');
        if (!empty($_POST['setpassword']) && !empty($_POST['oldpassword']))
        {
            if (!tokenOk($_POST['token'])) die('Wrong token.'); // Go away !

            // Make sure old password is correct.
            $oldhash = sha1($_POST['oldpassword'].$GLOBALS['login'].$GLOBALS['salt']);
            if ($oldhash!=$GLOBALS['hash']) { echo '<script language="JavaScript">alert("The old password is not correct.");document.location=\'?do=changepasswd\';</script>'; exit; }
            // Save new password
            $GLOBALS['salt'] = sha1(uniqid('',true).'_'.mt_rand()); // Salt renders rainbow-tables attacks useless.
            $GLOBALS['hash'] = sha1($_POST['setpassword'].$GLOBALS['login'].$GLOBALS['salt']);
            writeConfig();
            echo '<script language="JavaScript">alert("Your password has been changed.");document.location=\'?do=tools\';</script>';
            exit;
        }
        else
        {
            $token = getToken();
            $changepwdform= <<<HTML
<form method="POST" action="" name="changepasswordform" id="changepasswordform">
Old password: <input type="password" name="oldpassword">&nbsp; &nbsp;
New password: <input type="password" name="setpassword">
<input type="hidden" name="token" value="{$token}">
<input type="submit" name="Save" value="Save password" class="bigbutton"></form>
HTML;
            $data = array('pageheader'=>$changepwdform,'body'=>'','onload'=>'onload="document.changepasswordform.oldpassword.focus();"');
            templatePage($data);
            exit;
        }
    }
    
    // -------- User wants to change configuration
    if (startswith($_SERVER["QUERY_STRING"],'do=configure'))
    {
        if (!empty($_POST['title']) )
        {
            if (!tokenOk($_POST['token'])) die('Wrong token.'); // Go away !        
            $tz = 'UTC';
            if (!empty($_POST['continent']) && !empty($_POST['city']))
                if (isTZvalid($_POST['continent'],$_POST['city']))
                    $tz = $_POST['continent'].'/'.$_POST['city'];            
            $GLOBALS['timezone'] = $tz;
            $GLOBALS['title']=$_POST['title'];
            $GLOBALS['redirector']=$_POST['redirector'];
            writeConfig();
            echo '<script language="JavaScript">alert("Configuration was saved.");document.location=\'?do=tools\';</script>';
            exit;
        }
        else
        {
            $token = getToken();
            $title = htmlspecialchars( empty($GLOBALS['title']) ? '' : $GLOBALS['title'] , ENT_QUOTES);
            $redirector = htmlspecialchars( empty($GLOBALS['redirector']) ? '' : $GLOBALS['redirector'] , ENT_QUOTES);
            list($timezone_form,$timezone_js) = templateTZform($GLOBALS['timezone']);
            $timezone_html=''; if ($timezone_form!='') $timezone_html='<tr><td valign="top"><b>Timezone:</b></td><td>'.$timezone_form.'</td></tr>';
            $changepwdform= <<<HTML
${timezone_js}<form method="POST" action="" name="configform" id="configform"><input type="hidden" name="token" value="{$token}">
<table border="0" cellpadding="20">
<tr><td><b>Page title:</b></td><td><input type="text" name="title" id="title" size="50" value="{$title}"></td></tr>
{$timezone_html}
<tr><td valign="top"><b>Redirector</b></td><td><input type="text" name="redirector" id="redirector" size="50" value="{$redirector}"><br>(e.g. <i>http://anonym.to/?</i> will mask the HTTP_REFERER)</td></tr>
<tr><td></td><td align="right"><input type="submit" name="Save" value="Save config" class="bigbutton"></td></tr>
</table>
</form>
HTML;
            $data = array('pageheader'=>$changepwdform,'body'=>'','onload'=>'onload="document.configform.title.focus();"');
            templatePage($data);
            exit;
        }
    }    
  
    // -------- User wants to rename a tag or delete it
    if (startswith($_SERVER["QUERY_STRING"],'do=changetag'))
    {
        if (empty($_POST['fromtag']))
        {
            $token = getToken();
            $changetagform = <<<HTML
<form method="POST" action="" name="changetag" id="changetag">
<input type="hidden" name="token" value="{$token}">
Tag: <input type="text" name="fromtag" id="fromtag">
<input type="text" name="totag" style="margin-left:40px;"><input type="submit" name="renametag" value="Rename tag" class="bigbutton">      
&nbsp;&nbsp;or&nbsp; <input type="submit" name="deletetag" value="Delete tag" class="bigbutton" onClick="return confirmDeleteTag();"><br>(Case sensitive)</form> 
<script language="JavaScript">function confirmDeleteTag() { var agree=confirm("Are you sure you want to delete this tag from all links ?"); if (agree) return true ; else return false ; }</script>       
HTML;
            $data = array('pageheader'=>$changetagform,'body'=>'','onload'=>'onload="document.changetag.fromtag.focus();"');
            templatePage($data);
            exit;
        }
        if (!tokenOk($_POST['token'])) die('Wrong token.');
        
        if (!empty($_POST['deletetag']) && !empty($_POST['fromtag']))
        {
            $needle=trim($_POST['fromtag']);
            $linksToAlter = $LINKSDB->filterTags($needle,true); // true for case-sensitive tag search.
            foreach($linksToAlter as $key=>$value)
            {
                $tags = explode(' ',trim($value['tags']));
                unset($tags[array_search($needle,$tags)]); // Remove tag.
                $value['tags']=trim(implode(' ',$tags));
                $LINKSDB[$key]=$value;
            }
            $LINKSDB->savedb(); // save to disk
            pubsubhub();
            invalidateCaches();
            echo '<script language="JavaScript">alert("Tag was removed from '.count($linksToAlter).' links.");document.location=\'?\';</script>';
            exit;
        }

        // Rename a tag:
        if (!empty($_POST['renametag']) && !empty($_POST['fromtag']) && !empty($_POST['totag']))
        {
            $needle=trim($_POST['fromtag']);
            $linksToAlter = $LINKSDB->filterTags($needle,true); // true for case-sensitive tag search.
            foreach($linksToAlter as $key=>$value)
            {
                $tags = explode(' ',trim($value['tags']));
                $tags[array_search($needle,$tags)] = trim($_POST['totag']); // Remplace tags value.
                $value['tags']=trim(implode(' ',$tags));
                $LINKSDB[$key]=$value;
            }
            $LINKSDB->savedb(); // save to disk
            invalidateCaches();
            echo '<script language="JavaScript">alert("Tag was renamed in '.count($linksToAlter).' links.");document.location=\'?searchtags='.urlencode($_POST['totag']).'\';</script>';
            exit;
        }        
    }
    
    // -------- User wants to add a link without using the bookmarklet: show form.
    if (startswith($_SERVER["QUERY_STRING"],'do=addlink'))
    {
        $onload = 'onload="document.addform.post.focus();"';
        $addform= '<div id="headerform"><form method="GET" action="" name="addform" class="addform"><input type="text" name="post" style="width:50%;"> <input type="submit" value="Add link" class="bigbutton"></div>';
        $data = array('pageheader'=>$addform,'body'=>'','onload'=>$onload); 
        templatePage($data);
        exit;
    }    
    
    // -------- User clicked the "Save" button when editing a link: Save link to database.
    if (isset($_POST['save_edit']))
    {
        if (!tokenOk($_POST['token'])) die('Wrong token.'); // Go away !
        $tags = trim(preg_replace('/\s\s+/',' ', $_POST['lf_tags'])); // Remove multiple spaces.
        $linkdate=$_POST['lf_linkdate'];
        $link = array('title'=>trim($_POST['lf_title']),'url'=>trim($_POST['lf_url']),'description'=>trim($_POST['lf_description']),'private'=>(isset($_POST['lf_private']) ? 1 : 0),
                      'linkdate'=>$linkdate,'tags'=>str_replace(',',' ',$tags));        
        if ($link['title']=='') $link['title']=$link['url']; // If title is empty, use the URL as title.
        $LINKSDB[$linkdate] = $link;
        $LINKSDB->savedb(); // save to disk
        invalidateCaches();
        
        // If we are called from the bookmarklet, we must close the popup:
        if (isset($_GET['source']) && $_GET['source']=='bookmarklet') { echo '<script language="JavaScript">self.close();</script>'; exit; }
        $returnurl = ( isset($_POST['returnurl']) ? $_POST['returnurl'] : '?' );
        header('Location: '.$returnurl); // After saving the link, redirect to the page the user was on.
        exit;
    } 
    
    // -------- User clicked the "Cancel" button when editing a link.
    if (isset($_POST['cancel_edit']))
    {
        // If we are called from the bookmarklet, we must close the popup;
        if (isset($_GET['source']) && $_GET['source']=='bookmarklet') { echo '<script language="JavaScript">self.close();</script>'; exit; }
        $returnurl = ( isset($_POST['returnurl']) ? $_POST['returnurl'] : '?' );
        header('Location: '.$returnurl); // After canceling, redirect to the page the user was on.
        exit;    
    }

    // -------- User clicked the "Delete" button when editing a link : Delete link from database.
    if (isset($_POST['delete_link']))
    {
        if (!tokenOk($_POST['token'])) die('Wrong token.');
        // We do not need to ask for confirmation:
        // - confirmation is handled by javascript
        // - we are protected from XSRF by the token.
        $linkdate=$_POST['lf_linkdate'];
        unset($LINKSDB[$linkdate]);
        $LINKSDB->savedb(); // save to disk
        invalidateCaches();
        // If we are called from the bookmarklet, we must close the popup:
        if (isset($_GET['source']) && $_GET['source']=='bookmarklet') { echo '<script language="JavaScript">self.close();</script>'; exit; }
        $returnurl = ( isset($_POST['returnurl']) ? $_POST['returnurl'] : '?' );
        header('Location: '.$returnurl); // After deleting the link, redirect to the page the user was on.
        exit;
    }    
    
    // -------- User clicked the "EDIT" button on a link: Display link edit form.
    if (isset($_GET['edit_link']))  
    {
        $link = $LINKSDB[$_GET['edit_link']];  // Read database
        if (!$link) { header('Location: ?'); exit; } // Link not found in database.
        list($editform,$onload)=templateEditForm($link);
        $data = array('pageheader'=>$editform,'body'=>'','onload'=>$onload); 
        templatePage($data);    
        exit;       
    }
    
    // -------- User want to post a new link: Display link edit form.
    if (isset($_GET['post']))
    {
        $url=$_GET['post'];

        // We remove the annoying parameters added by FeedBurner and GoogleFeedProxy (?utm_source=...)
        $i=strpos($url,'&utm_source='); if ($i!==false) $url=substr($url,0,$i);
        $i=strpos($url,'?utm_source='); if ($i!==false) $url=substr($url,0,$i);
        $i=strpos($url,'#xtor=RSS-'); if ($i!==false) $url=substr($url,0,$i);
        
        $link_is_new = false;
        $link = $LINKSDB->getLinkFromUrl($url); // Check if URL is not already in database (in this case, we will edit the existing link)
        if (!$link) 
        {
            $link_is_new = true;  // This is a new link
            $linkdate = strval(date('Ymd_His'));
            $title = (empty($_GET['title']) ? '' : $_GET['title'] ); // Get title if it was provided in URL (by the bookmarklet).
            $description=''; $tags=''; $private=0;
            if (($url!='') && parse_url($url,PHP_URL_SCHEME)=='') $url = 'http://'.$url;                
            // If this is an HTTP link, we try go get the page to extact the title (otherwise we will to straight to the edit form.)
            if (empty($title) && parse_url($url,PHP_URL_SCHEME)=='http')
            {
                list($status,$headers,$data) = getHTTP($url,4); // Short timeout to keep the application responsive.
                // FIXME: Decode charset according to specified in either 1) HTTP response headers or 2) <head> in html 
                if (strpos($status,'200 OK')!==false) $title=html_entity_decode(html_extract_title($data),ENT_QUOTES,'UTF-8');
            }
            if ($url=='') $url='?'.smallHash($linkdate); // In case of empty URL, this is just a text (with a link that point to itself)
            $link = array('linkdate'=>$linkdate,'title'=>$title,'url'=>$url,'description'=>$description,'tags'=>$tags,'private'=>0); 
        }
        list($editform,$onload)=templateEditForm($link,$link_is_new); 
        $data = array('pageheader'=>$editform,'body'=>'','onload'=>$onload); 
        templatePage($data);    
        exit;
    }
    
    // -------- Export as Netscape Bookmarks HTML file.
    if (startswith($_SERVER["QUERY_STRING"],'do=export'))
    {
        if (empty($_GET['what']))
        {
            $toolbar= <<<HTML
<div id="toolsdiv">
    <a href="?do=export&what=all"><b>Export all</b></a> - Export all links<br><br>
    <a href="?do=export&what=public"><b>Export public</b></a> - Export public links only<br><br>
    <a href="?do=export&what=private"><b>Export private</b></a> - Export private links only<br><br>
</div>
HTML;
            $data = array('pageheader'=>$toolbar,'body'=>'','onload'=>''); 
            templatePage($data);
            exit;
        }
        $exportWhat=$_GET['what'];
        if (!array_intersect(array('all','public','private'),array($exportWhat))) die('What are you trying to export ???');
       
        header('Content-Type: text/html; charset=utf-8');
        header('Content-disposition: attachment; filename=bookmarks_'.$exportWhat.'_'.strval(date('Ymd_His')).'.html');
        echo <<<HTML
<!DOCTYPE NETSCAPE-Bookmark-file-1>
<!-- This is an automatically generated file.
     It will be read and overwritten.
     DO NOT EDIT! -->
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<TITLE>Bookmarks</TITLE>
<H1>Bookmarks</H1>
HTML;
        foreach($LINKSDB as $link)
        {
            if ($exportWhat=='all' ||
               ($exportWhat=='private' && $link['private']!=0) ||
               ($exportWhat=='public' && $link['private']==0))
            {
                echo '<DT><A HREF="'.htmlspecialchars($link['url']).'" ADD_DATE="'.linkdate2timestamp($link['linkdate']).'" PRIVATE="'.$link['private'].'"';
                if ($link['tags']!='') echo ' TAGS="'.htmlspecialchars(str_replace(' ',',',$link['tags'])).'"';
                echo '>'.htmlspecialchars($link['title'])."</A>\n";
                if ($link['description']!='') echo '<DD>'.htmlspecialchars($link['description'])."\n";
            }
        }
        echo '<!-- Shaarli '.$exportWhat.' bookmarks export on '.date('Y/m/d H:i:s')."-->\n";
        exit;
    }            

    // -------- User is uploading a file for import
    if (startswith($_SERVER["QUERY_STRING"],'do=upload'))
    {
        // If file is too big, some form field may be missing.
        if (!isset($_POST['token']) || (!isset($_FILES)) || (isset($_FILES['filetoupload']['size']) && $_FILES['filetoupload']['size']==0))
        {
            $returnurl = ( empty($_SERVER['HTTP_REFERER']) ? '?' : $_SERVER['HTTP_REFERER'] );
            echo '<script language="JavaScript">alert("The file you are trying to upload is probably bigger than what this webserver can accept ('.getMaxFileSize().' bytes). Please upload in smaller chunks.");document.location=\''.htmlspecialchars($returnurl).'\';</script>';
            exit;
        }         
        if (!tokenOk($_POST['token'])) die('Wrong token.');
        importFile();
        exit;
    }     
    
    // -------- Show upload/import dialog:
    if (startswith($_SERVER["QUERY_STRING"],'do=import'))
    {    
        $token = getToken();
        $maxfilesize=getMaxFileSize();
        $onload = 'onload="document.uploadform.filetoupload.focus();"';
        $uploadform=<<<HTML
<div id="uploaddiv">
Import Netscape html bookmarks (as exported from Firefox/Chrome/Opera/delicious/diigo...) (Max: {$maxfilesize} bytes).
<form method="POST" action="?do=upload" enctype="multipart/form-data" name="uploadform" id="uploadform">
    <input type="hidden" name="token" value="{$token}">
    <input type="file" name="filetoupload" size="80">
    <input type="hidden" name="MAX_FILE_SIZE" value="{$maxfilesize}">
    <input type="submit" name="import_file" value="Import" class="bigbutton"><br>
    <input type="checkbox" name="private">&nbsp;Import all links as private<br>
    <input type="checkbox" name="overwrite">&nbsp;Overwrite existing links
</form>
</div>
HTML;
        $data = array('pageheader'=>$uploadform,'body'=>'','onload'=>$onload  ); 
        templatePage($data);
        exit;
    }    

    // -------- Otherwise, simply display search form and links:
    $searchform=<<<HTML
<div id="headerform" style="width:100%; white-space:nowrap;";>
    <form method="GET" class="searchform" name="searchform"><input type="text" name="searchterm" style="width:30%" value=""> <input type="submit" value="Search" class="bigbutton"></form>
    <form method="GET" class="tagfilter" name="tagfilter" style="margin-left:24px;"><input type="text" name="searchtags" id="searchtags" style="width:10%" value=""> <input type="submit" value="Filter by tag" class="bigbutton"></form>
</div>
HTML;
    $data = array('pageheader'=>$searchform,'body'=>templateLinkList(),'onload'=>''); 
    templatePage($data);
    exit;
}    

// -----------------------------------------------------------------------------------------------
// Process the import file form.
function importFile()
{
    global $LINKSDB;
    $filename=$_FILES['filetoupload']['name'];
    $filesize=$_FILES['filetoupload']['size'];    
    $data=file_get_contents($_FILES['filetoupload']['tmp_name']);
    $private = (empty($_POST['private']) ? 0 : 1); // Should the links be imported as private ?
    $overwrite = !empty($_POST['overwrite']) ; // Should the imported links overwrite existing ones ?
    $import_count=0;

    // Sniff file type:
    $type='unknown';
    if (startsWith($data,'<!DOCTYPE NETSCAPE-Bookmark-file-1>')) $type='netscape'; // Netscape bookmark file (aka Firefox).
    
    // Then import the bookmarks.
    if ($type=='netscape')
    {
        // This is a standard Netscape-style bookmark file.
        // This format is supported by all browsers (except IE, of course), also delicious, diigo and others.      
        // I didn't want to use DOM... anyway, this is FAST (less than 1 second to import 7200 links (2.1 Mb html file)).
        foreach(explode('<DT>',$data) as $html) // explode is very fast
        {
            $link = array('linkdate'=>'','title'=>'','url'=>'','description'=>'','tags'=>'','private'=>0);  
            $d = explode('<DD>',$html);
            if (startswith($d[0],'<A '))
            {
                $link['description'] = (isset($d[1]) ? html_entity_decode(trim($d[1]),ENT_QUOTES,'UTF-8') : '');  // Get description (optional)
                preg_match('!<A .*?>(.*?)</A>!i',$d[0],$matches); $link['title'] = (isset($matches[1]) ? trim($matches[1]) : '');  // Get title
                $link['title'] = html_entity_decode($link['title'],ENT_QUOTES,'UTF-8');
                preg_match_all('! ([A-Z_]+)=\"(.*?)"!i',$html,$matches,PREG_SET_ORDER);  // Get all other attributes
                foreach($matches as $m)
                {
                    $attr=$m[1]; $value=$m[2];
                    if ($attr=='HREF') $link['url']=html_entity_decode($value,ENT_QUOTES,'UTF-8');
                    elseif ($attr=='ADD_DATE') $link['linkdate']=date('Ymd_His',intval($value));
                    elseif ($attr=='PRIVATE') $link['private']=($value=='0'?0:1);
                    elseif ($attr=='TAGS') $link['tags']=html_entity_decode(str_replace(',',' ',$value),ENT_QUOTES,'UTF-8');
                }     
                if ($link['linkdate']!='' && $link['url']!='' && ($overwrite || empty($LINKSDB[$link['linkdate']])))
                {
                    if ($private==1) $link['private']=1;
                    $LINKSDB[$link['linkdate']] = $link;
                    $import_count++;
                }
            }     
        }
        $LINKSDB->savedb();
        invalidateCaches();
        echo '<script language="JavaScript">alert("File '.$filename.' ('.$filesize.' bytes) was successfully processed: '.$import_count.' links imported.");document.location=\'?\';</script>';            
    }
    else
    {
        echo '<script language="JavaScript">alert("File '.$filename.' ('.$filesize.' bytes) has an unknown file format. Nothing was imported.");document.location=\'?\';</script>';
    }
}    

// -----------------------------------------------------------------------------------------------
/* Template for the edit link form
    Input: $link : link to edit (assocative array item as returned by the LINKDB class)
Output: An array : (string) : The html code of the edit link form.
                   (string) : The proper onload to use in body.
    Example: list($html,$onload)=templateEditForm($mylinkdb['20110805_124532']);
             echo $html;
*/
function templateEditForm($link,$link_is_new=false)
{
    $url=htmlspecialchars($link['url']);
    $title=htmlspecialchars($link['title']);
    $tags=htmlspecialchars($link['tags']);
    $description=htmlspecialchars($link['description']);    
    $private = ($link['private']==0 ? '' : 'checked="yes"');   
    
    // Automatically focus on empty fields:
    $onload='onload="document.linkform.lf_tags.focus();"';
    if ($description=='') $onload='onload="document.linkform.lf_description.focus();"';
    if ($title=='') $onload='onload="document.linkform.lf_title.focus();"';    
    
    // Do not show "Delete" button if this is a new link.
    $delete_button = '<input type="submit" value="Delete" name="delete_link" class="bigbutton" style="margin-left:180px;" onClick="return confirmDeleteLink();">';
    if ($link_is_new) $delete_button='';
    
    $token=getToken(); // XSRF protection. 
    $returnurl_html = (isset($_SERVER['HTTP_REFERER']) ? '<input type="hidden" name="returnurl" value="'.htmlspecialchars($_SERVER['HTTP_REFERER']).'">' : '');
    $editlinkform=<<<HTML
<div id="editlinkform">
    <form method="post" name="linkform">
        <input type="hidden" name="lf_linkdate" value="{$link['linkdate']}">
        <i>URL</i><br><input type="text" name="lf_url" value="{$url}" style="width:100%"><br>
        <i>Title</i><br><input type="text" name="lf_title" value="{$title}" style="width:100%"><br>
        <i>Description</i><br><textarea name="lf_description" rows="4" cols="25" style="width:100%">{$description}</textarea><br>
        <i>Tags</i><br><input type="text" id="lf_tags" name="lf_tags" value="{$tags}" style="width:100%"><br>
        <input type="checkbox" {$private} style="margin:7 0 10 0;" name="lf_private">&nbsp;<i>Private</i><br>
        <input type="submit" value="Save" name="save_edit" class="bigbutton" style="margin-left:40px;">
        <input type="submit" value="Cancel" name="cancel_edit" class="bigbutton" style="margin-left:40px;">
        {$delete_button}
        <input type="hidden" name="token" value="{$token}">
        {$returnurl_html}
    </form>
</div>    
HTML;
    return array($editlinkform,$onload);
}        


// -----------------------------------------------------------------------------------------------
// Template for the list of links.
// Returns html code to show the list of link according to parameters passed in URL (search terms, page...)
function templateLinkList()
{     
    global $LINKSDB;

    // Search according to entered search terms:
    $linksToDisplay=array();
    $searched='';
    if (!empty($_GET['searchterm'])) // Fulltext search
    {
        $linksToDisplay = $LINKSDB->filterFulltext(trim($_GET['searchterm']));
        $searched=count($linksToDisplay).' results for <i>'.htmlspecialchars(trim($_GET['searchterm'])).'</i>:';
    }
    elseif (!empty($_GET['searchtags'])) // Search by tag
    {
        $linksToDisplay = $LINKSDB->filterTags(trim($_GET['searchtags']));
        $tagshtml=''; foreach(explode(' ',trim($_GET['searchtags'])) as $tag) $tagshtml.='<span class="linktag" title="Remove tag"><a href="?removetag='.htmlspecialchars($tag).'">'.htmlspecialchars($tag).' <span style="border-left:1px solid #aaa; padding-left:5px; color:#6767A7;">x</span></a></span> ';
        $searched=''.count($linksToDisplay).' results for tags '.$tagshtml.':';    
    }
    elseif (preg_match('/[a-zA-Z0-9-_@]{6}/',$_SERVER["QUERY_STRING"])) // Detect smallHashes in URL
    {
        $linksToDisplay = $LINKSDB->filterSmallHash($_SERVER["QUERY_STRING"]);
    }
    else
        $linksToDisplay = $LINKSDB;  // otherwise, display without filtering.
    if ($searched!='') $searched='<div id="searchcriteria">'.$searched.'</div>';
    $linklist='';
    $actions='';
    
    // Handle paging.
    /* Can someone explain to me why you get the following error when using array_keys() on an object which implements the interface ArrayAccess ???
       "Warning: array_keys() expects parameter 1 to be array, object given in ... "
       If my class implements ArrayAccess, why won't array_keys() accept it ?  ( $keys=array_keys($linksToDisplay); )
    */
    $keys=array(); foreach($linksToDisplay as $key=>$value) { $keys[]=$key; } // Stupid and ugly. Thanks php.

    // If there is only a single link, we change on-the-fly the title of the page.
    if (count($linksToDisplay)==1) $GLOBALS['pagetitle'] = $linksToDisplay[$keys[0]]['title'].' - '.$GLOBALS['title'];

    $pagecount = ceil(count($keys)/$_SESSION['LINKS_PER_PAGE']);
    $pagecount = ($pagecount==0 ? 1 : $pagecount);
    $page=( empty($_GET['page']) ? 1 : intval($_GET['page']));
    $page = ( $page<1 ? 1 : $page );
    $page = ( $page>$pagecount ? $pagecount : $page );
    $i = ($page-1)*$_SESSION['LINKS_PER_PAGE']; // Start index.
    $end = $i+$_SESSION['LINKS_PER_PAGE'];  
    $redir = empty($GLOBALS['redirector']) ? '' : $GLOBALS['redirector']; // optional redirector URL
    
    while ($i<$end && $i<count($keys))
    {  
        $link = $linksToDisplay[$keys[$i]];
        $description=text2clickable(htmlspecialchars($link['description']));
        $title=$link['title'];
        $classLi =  $i%2!=0 ? '' : 'class="publicLinkHightLight"';
        $classprivate = ($link['private']==0 ? $classLi : 'class="private"');
        if (isLoggedIn()) $actions=' <form method="GET" class="buttoneditform"><input type="hidden" name="edit_link" value="'.$link['linkdate'].'"><input type="submit" value="Edit" class="smallbutton"></form>';
        $tags='';
        if ($link['tags']!='')
        {
            foreach(explode(' ',$link['tags']) as $tag) { $tags.='<span class="linktag" title="Add tag"><a href="?addtag='.htmlspecialchars($tag).'">'.htmlspecialchars($tag).'</a></span> '; }
            $tags='<div class="linktaglist">'.$tags.'</div>';
        }
        $linklist.='<li '.$classprivate.'><div class="thumbnail">'.thumbnail($link['url']).'</div>';
        $linklist.='<div class="linkcontainer"><span class="linktitle"><a href="'.$redir.htmlspecialchars($link['url']).'">'.htmlspecialchars($title).'</a></span>'.$actions.'<br>';
        if ($description!='') $linklist.='<div class="linkdescription">'.nl2br($description).'</div><br>';
        if (!$GLOBALS['config']['HIDE_TIMESTAMPS'] || isLoggedIn()) $linklist.='<span class="linkdate" title="Permalink"><a href="?'.smallHash($link['linkdate']).'">'.htmlspecialchars(linkdate2locale($link['linkdate'])).' - permalink</a> - </span>';
        else $linklist.='<span class="linkdate" title="Short link here"><a href="?'.smallHash($link['linkdate']).'">permalink</a> - </span>';
        $linklist.='<span class="linkurl" title="Short link">'.htmlspecialchars($link['url']).'</span><br>'.$tags."</div></li>\n";  
        $i++;
    } 
    
    // Show paging.
    $searchterm= ( empty($_GET['searchterm']) ? '' : '&searchterm='.$_GET['searchterm'] );
    $searchtags= ( empty($_GET['searchtags']) ? '' : '&searchtags='.$_GET['searchtags'] );
    $paging=''; 
    if ($i!=count($keys)) $paging.='<a href="?page='.($page+1).$searchterm.$searchtags.'">&#x25C4;Older</a>';
    $paging.= '<span style="color:#fff; padding:0 20 0 20;">page '.$page.' / '.$pagecount.'</span>';
    if ($page>1) $paging.='<a href="?page='.($page-1).$searchterm.$searchtags.'">Newer&#x25BA;</a>';
    $linksperpage = <<<HTML
<div style="float:right; padding-right:5px;">
Links per page: <a href="?linksperpage=20">20</a> <a href="?linksperpage=50">50</a> <a href="?linksperpage=100">100</a>
 <form method="GET" style="display:inline;" class="linksperpage"><input type="text" name="linksperpage" size="2" style="height:15px;"></form></div>
HTML;
    $paging = '<div class="paging">'.$linksperpage.$paging.'</div>';
    $linklist='<div id="linklist">'.$paging.$searched.'<ul>'.$linklist.'</ul>'.$paging.'</div>';
    return $linklist;
}

// Returns the HTML code to display a thumbnail for a link
// with a link to the original URL.
// Understands various services (youtube.com...)
// Input: $url = url for which the thumbnail must be found.
//        $href = if provided, this URL will be followed instead of $url 
function thumbnail($url,$href=false)
{
    if (!$GLOBALS['config']['ENABLE_THUMBNAILS']) return '';
    
    if ($href==false) $href=$url;
    
    // For most hosts, the URL of the thumbnail can be easily deduced from the URL of the link.
    // (eg. http://www.youtube.com/watch?v=spVypYk4kto --->  http://img.youtube.com/vi/spVypYk4kto/default.jpg )
    //                                     ^^^^^^^^^^^                                 ^^^^^^^^^^^
    $domain = parse_url($url,PHP_URL_HOST);
    if ($domain=='youtube.com' || $domain=='www.youtube.com')
    {
        parse_str(parse_url($url,PHP_URL_QUERY), $params); // Extract video ID and get thumbnail
        if (!empty($params['v'])) return '<a href="'.htmlspecialchars($href).'"><img src="http://img.youtube.com/vi/'.htmlspecialchars($params['v']).'/default.jpg" width="120" height="90"></a>';
    }
    if ($domain=='youtu.be') // Youtube short links
    {
        $path = parse_url($url,PHP_URL_PATH);
        return '<a href="'.htmlspecialchars($href).'"><img src="http://img.youtube.com/vi'.htmlspecialchars($path).'/default.jpg" width="120" height="90"></a>';
    } 
    if ($domain=='imgur.com')
    {
        $path = parse_url($url,PHP_URL_PATH);
        if (startsWith($path,'/a/')) return ''; // Thumbnails for albums are not available.
        if (startsWith($path,'/r/')) return '<a href="'.htmlspecialchars($href).'"><img src="http://i.imgur.com/'.htmlspecialchars(basename($path)).'s.jpg" width="90" height="90"></a>';
        if (startsWith($path,'/gallery/')) return '<a href="'.htmlspecialchars($href).'"><img src="http://i.imgur.com'.htmlspecialchars(substr($path,8)).'s.jpg" width="90" height="90"></a>';
        if (substr_count($path,'/')==1) return '<a href="'.htmlspecialchars($href).'"><img src="http://i.imgur.com/'.htmlspecialchars(substr($path,1)).'s.jpg" width="90" height="90"></a>';
    }
    if ($domain=='i.imgur.com')
    {
        $pi = pathinfo(parse_url($url,PHP_URL_PATH));
        if (!empty($pi['filename'])) return '<a href="'.htmlspecialchars($href).'"><img src="http://i.imgur.com/'.htmlspecialchars($pi['filename']).'s.jpg" width="90" height="90"></a>';
    } 
    if ($domain=='dailymotion.com' || $domain=='www.dailymotion.com')
    {
        if (strpos($url,'dailymotion.com/video/')!==false)
        {
            $thumburl=str_replace('dailymotion.com/video/','dailymotion.com/thumbnail/video/',$url);
            return '<a href="'.htmlspecialchars($href).'"><img src="'.htmlspecialchars($thumburl).'" width="120" style="height:auto;"></a>';
        }
    } 
    if (endsWith($domain,'.imageshack.us'))
    {
        $ext=strtolower(pathinfo($url,PATHINFO_EXTENSION));
        if ($ext=='jpg' || $ext=='jpeg' || $ext=='png' || $ext=='gif')
        {
            $thumburl = substr($url,0,strlen($url)-strlen($ext)).'th.'.$ext;
            return '<a href="'.htmlspecialchars($href).'"><img src="'.htmlspecialchars($thumburl).'" width="120" style="height:auto;"></a>';
        }
    }

    // Some other hosts are SLOW AS HELL and usually require an extra HTTP request to get the thumbnail URL.
    // So we deport the thumbnail generation in order not to slow down page generation
    // (and we also cache the thumbnail)
    
    if (!$GLOBALS['config']['ENABLE_LOCALCACHE']) return ''; // If local cache is disabled, no thumbnails for services which require the use a local cache.
    
    if ($domain=='flickr.com' || endsWith($domain,'.flickr.com') || $domain=='vimeo.com')
    {
    	if ($domain=='vimeo.com')
    	{   // Make sure this vimeo url points to a video (/xxx... where xxx is numeric)
    		$path = parse_url($url,PHP_URL_PATH); 
    		if (!preg_match('!/\d+.+?!',$path)) return ''; // This is not a single video URL.
    	}
        $sign = hash_hmac('sha256', $url, $GLOBALS['salt']); // We use the salt to sign data (it's random, secret, and specific to each installation)
        return '<a href="'.htmlspecialchars($href).'"><img src="?do=genthumbnail&hmac='.htmlspecialchars($sign).'&url='.urlencode($url).'" width="120" style="height:auto;"></a>';
    }

    // For all other, we try to make a thumbnail of links ending with .jpg/jpeg/png/gif
    // Technically speaking, we should download ALL links and check their Content-Type to see if they are images.
    // But using the extension will do.
    $ext=strtolower(pathinfo($url,PATHINFO_EXTENSION));
    if ($ext=='jpg' || $ext=='jpeg' || $ext=='png' || $ext=='gif')
    {
        $sign = hash_hmac('sha256', $url, $GLOBALS['salt']); // We use the salt to sign data (it's random, secret, and specific to each installation)
        return '<a href="'.htmlspecialchars($href).'"><img src="?do=genthumbnail&hmac='.htmlspecialchars($sign).'&url='.urlencode($url).'" width="120" style="height:auto;"></a>';        
    }
    return ''; // No thumbnail.

}

// -----------------------------------------------------------------------------------------------
// Template for the whole page.
/* Input: $data (associative array).
                Keys: 'body' : body of HTML document
                      'pageheader' : html code to show in page header (top of page)
                      'onload' : optional onload javascript for the <body>
*/
function templatePage($data)
{
    global $STARTTIME;
    global $LINKSDB;
    $shaarli_version = shaarli_version;
    
    $newversion=checkUpdate();
    if ($newversion!='') $newversion='<div id="newversion"><span style="text-decoration:blink;">&#x25CF;</span> Shaarli '.htmlspecialchars($newversion).' is <a href="http://sebsauvage.net/wiki/doku.php?id=php:shaarli#download">available</a>.</div>';
    $linkcount = count($LINKSDB);
    $open='';
    if ($GLOBALS['config']['OPEN_SHAARLI'])
    {
        $menu=' <a href="?do=tools">Tools</a> &nbsp;<a href="?do=addlink"><b>Add link</b></a>';
        $open='Open ';
    }
    else
        $menu=(isLoggedIn() ? ' <a href="?do=logout">Logout</a> &nbsp;<a href="?do=tools">Tools</a> &nbsp;<a href="?do=addlink"><b>Add link</b></a>' : ' <a href="?do=login">Login</a>');          
    
    foreach(array('pageheader','body','onload') as $k) // make sure all required fields exist (put an empty string if not).
    {
        if (!array_key_exists($k,$data)) $data[$k]='';
    }
    $jsincludes=''; $jsincludes_bottom = '';
    if ($GLOBALS['config']['OPEN_SHAARLI'] || isLoggedIn())
    { 
        $source = serverUrl().$_SERVER['SCRIPT_NAME'];  
        $jsincludes='<script language="JavaScript" src="jquery.min.js"></script><script language="JavaScript" src="jquery-ui.custom.min.js"></script>'; 
        $jsincludes_bottom = <<<JS
<script language="JavaScript">
$(document).ready(function() 
{
    $('#lf_tags').autocomplete({source:'{$source}?ws=tags',minLength:1});
    $('#searchtags').autocomplete({source:'{$source}?ws=tags',minLength:1});
    $('#fromtag').autocomplete({source:'{$source}?ws=singletag',minLength:1});  
});
</script>
JS;
    }
    $feedurl=htmlspecialchars(serverUrl().$_SERVER['SCRIPT_NAME']); 
    $searchcrits=''; // Search criteria
    if (!empty($_GET['searchtags'])) $searchcrits.='&searchtags='.$_GET['searchtags'];
    elseif (!empty($_GET['searchterm'])) $searchcrits.='&searchterm='.$_GET['searchterm'];
    $filtered_feed= ($searchcrits=='' ? '' : 'Filtered ');
    $version=shaarli_version;

    $title = htmlspecialchars( $GLOBALS['title'] );
    $pagetitle = htmlspecialchars( empty($GLOBALS['pagetitle']) ? $title : $GLOBALS['pagetitle'] );
    echo <<<HTML
<html>
<head>
<title>{$pagetitle}</title>
<link rel="alternate" type="application/rss+xml" href="{$feedurl}?do=rss{$searchcrits}" title="{$filtered_feed}RSS Feed" />
<link rel="alternate" type="application/atom+xml" href="{$feedurl}?do=atom{$searchcrits}" title="{$filtered_feed}ATOM Feed" />
<link href="./images/favicon.ico" rel="shortcut icon" type="image/x-icon" />
<link type="text/css" rel="stylesheet" href="shaarli.css?version={$version}" />
{$jsincludes}
</head>
<body {$data['onload']}>{$newversion}
<div id="pageheader"><div id="logo" title="Share your links !"></div><div style="float:right; font-style:italic; color:#bbb; text-align:right; padding:0 5 0 0;">Shaare your links...<br>{$linkcount} links</div>
    <span id="shaarli_title"><a href="?">{$title}</a></span> - <a href="?">Home</a>&nbsp;{$menu}&nbsp;<a href="{$feedurl}?do=rss{$searchcrits}">RSS Feed</a> <a href="{$feedurl}?do=atom{$searchcrits}" style="padding-left:10px;">ATOM Feed</a>
&nbsp;&nbsp; <a href="?do=tagcloud">Tag cloud</a>&nbsp;&nbsp; <a href="?do=picwall{$searchcrits}">Picture wall</a>
{$data['pageheader']}
</div>
{$data['body']}

HTML;
    $exectime = round(microtime(true)-$STARTTIME,4);
    echo '<div id="footer"><b><a href="http://sebsauvage.net/wiki/doku.php?id=php:shaarli">Shaarli '.shaarli_version.'</a></b> - The personal, minimalist, super-fast, no-database delicious clone. By sebsauvage.net. Theme by idleman.fr.<br>Who gives a shit that this page was generated in '.$exectime.' seconds&nbsp;?</div>';
    if (isLoggedIn()) echo '<script language="JavaScript">function confirmDeleteLink() { var agree=confirm("Are you sure you want to delete this link ?"); if (agree) return true ; else return false ; }</script>';
    echo $jsincludes_bottom.'</body></html>';
}

// -----------------------------------------------------------------------------------------------
// Installation
// This function should NEVER be called if the file data/config.php exists.
function install()
{
    // On free.fr host, make sure the /sessions directory exists, otherwise login will not work.
    if (endsWith($_SERVER['SERVER_NAME'],'.free.fr') && !is_dir($_SERVER['DOCUMENT_ROOT'].'/sessions')) mkdir($_SERVER['DOCUMENT_ROOT'].'/sessions',0705);
    
    if (!empty($_POST['setlogin']) && !empty($_POST['setpassword']))
    {
        $tz = 'UTC';
        if (!empty($_POST['continent']) && !empty($_POST['city']))
            if (isTZvalid($_POST['continent'],$_POST['city']))
                $tz = $_POST['continent'].'/'.$_POST['city'];
        $GLOBALS['timezone'] = $tz;        
        // Everything is ok, let's create config file.
        $GLOBALS['login'] = $_POST['setlogin'];
        $GLOBALS['salt'] = sha1(uniqid('',true).'_'.mt_rand()); // Salt renders rainbow-tables attacks useless.
        $GLOBALS['hash'] = sha1($_POST['setpassword'].$GLOBALS['login'].$GLOBALS['salt']);
        $GLOBALS['title'] = (empty($_POST['title']) ? 'Shared links on '.htmlspecialchars(serverUrl().$_SERVER['SCRIPT_NAME']) : $_POST['title'] );
        writeConfig();
        echo '<script language="JavaScript">alert("Shaarli is now configured. Please enter your login/password and start shaaring your links !");document.location=\'?do=login\';</script>';        
        exit;            
    }
     
    // Display config form:        
    list($timezone_form,$timezone_js) = templateTZform();
    $timezone_html=''; if ($timezone_form!='') $timezone_html='<tr><td valign="top"><b>Timezone:</b></td><td>'.$timezone_form.'</td></tr>';
    echo <<<HTML
<html><head><title>Shaarli - Configuration</title><link type="text/css" rel="stylesheet" href="shaarli.css" />${timezone_js}</head>
<body onload="document.installform.setlogin.focus();" style="padding:20px;"><h1>Shaarli - Shaare your links...</h1>
It looks like it's the first time you run Shaarli. Please configure it:<br>
<form method="POST" action="" name="installform" id="installform" style="border:1px solid black; padding:10 10 10 10;">
<table border="0" cellpadding="20">
<tr><td><b>Login:</b></td><td><input type="text" name="setlogin" size="30"></td></tr>
<tr><td><b>Password:</b></td><td><input type="password" name="setpassword" size="30"></td></tr>
{$timezone_html}
<tr><td><b>Page title:</b></td><td><input type="text" name="title" size="30"></td></tr>
<tr><td></td><td align="right"><input type="submit" name="Save" value="Save config" class="bigbutton"></td></tr>
</table>
</form></body></html>
HTML;
    exit;
}

// Generates the timezone selection form and javascript.
// Input: (optional) current timezone (can be 'UTC/UTC'). It will be pre-selected.
// Output: array(html,js)
// Example: list($htmlform,$js) = templateTZform('Europe/Paris');  // Europe/Paris pre-selected.
// Returns array('','') if server does not support timezones list. (eg. php 5.1 on free.fr)
function templateTZform($ptz=false)
{
    if (function_exists('timezone_identifiers_list')) // because of old php version (5.1) which can be found on free.fr
    {
        // Try to split the provided timezone.
        if ($ptz==false) { $l=timezone_identifiers_list(); $ptz=$l[0]; }
        $spos=strpos($ptz,'/'); $pcontinent=substr($ptz,0,$spos); $pcity=substr($ptz,$spos+1);
      
        // Display config form:
        $timezone_form = '';
        $timezone_js = '';
        // The list is in the forme "Europe/Paris", "America/Argentina/Buenos_Aires"...
        // We split the list in continents/cities.
        $continents = array();
        $cities = array();
        foreach(timezone_identifiers_list() as $tz) 
        {
            if ($tz=='UTC') $tz='UTC/UTC';
            $spos = strpos($tz,'/');
            if ($spos!==false)
            {
                $continent=substr($tz,0,$spos); $city=substr($tz,$spos+1);
                $continents[$continent]=1;
                if (!isset($cities[$continent])) $cities[$continent]=array();
                $cities[$continent].='<option value="'.$city.'"'.($pcity==$city?'selected':'').'>'.$city.'</option>';
            }
        }
        $continents_html = '';
        $continents = array_keys($continents);
        foreach($continents as $continent)
            $continents_html.='<option  value="'.$continent.'"'.($pcontinent==$continent?'selected':'').'>'.$continent.'</option>';  
        $cities_html = $cities[$pcontinent];
        $timezone_form = "Continent: <select name=\"continent\" id=\"continent\" onChange=\"onChangecontinent();\">${continents_html}</select><br /><br />";
        $timezone_form .= "City: <select name=\"city\" id=\"city\">${cities[$pcontinent]}</select><br /><br />"; 
        $timezone_js = "<script language=\"JavaScript\">";
        $timezone_js .= "function onChangecontinent(){document.getElementById(\"city\").innerHTML = citiescontinent[document.getElementById(\"continent\").value];}"; 
        $timezone_js .= "var citiescontinent = ".json_encode($cities).";" ;
        $timezone_js .= "</script>" ;
        return array($timezone_form,$timezone_js);
    }
    return array('','');
}

// Tells if a timezone is valid or not.
// If not valid, returns false.
// If system does not support timezone list, returns false.
function isTZvalid($continent,$city)
{
    $tz = $continent.'/'.$city;
    if (function_exists('timezone_identifiers_list')) // because of old php version (5.1) which can be found on free.fr
    {
        if (in_array($tz, timezone_identifiers_list())) // it's a valid timezone ?
                    return true;
    }
    return false;
}


// Webservices (for use with jQuery/jQueryUI)
// eg.  index.php?ws=tags&term=minecr
function processWS()
{
    if (empty($_GET['ws']) || empty($_GET['term'])) return;
    $term = $_GET['term'];
    global $LINKSDB;
    header('Content-Type: application/json; charset=utf-8');

    // Search in tags (case insentitive, cumulative search)
    if ($_GET['ws']=='tags')
    { 
        $tags=explode(' ',str_replace(',',' ',$term)); $last = array_pop($tags); // Get the last term ("a b c d" ==> "a b c", "d")
        $addtags=''; if ($tags) $addtags=implode(' ',$tags).' '; // We will pre-pend previous tags
        $suggested=array();
        /* To speed up things, we store list of tags in session */
        if (empty($_SESSION['tags'])) $_SESSION['tags'] = $LINKSDB->allTags(); 
        foreach($_SESSION['tags'] as $key=>$value)
        {
            if (startsWith($key,$last,$case=false) && !in_array($key,$tags)) $suggested[$addtags.$key.' ']=0;
        }      
        echo json_encode(array_keys($suggested));
        exit;
    }
    
    // Search a single tag (case sentitive, single tag search)
    if ($_GET['ws']=='singletag')
    { 
        /* To speed up things, we store list of tags in session */
        if (empty($_SESSION['tags'])) $_SESSION['tags'] = $LINKSDB->allTags(); 
        foreach($_SESSION['tags'] as $key=>$value)
        {
            if (startsWith($key,$term,$case=true)) $suggested[$key]=0;
        }      
        echo json_encode(array_keys($suggested));
        exit;
    }    
}

// Re-write configuration file according to globals.
// Requires some $GLOBALS to be set (login,hash,salt,title).
// If the config file cannot be saved, an error message is dislayed and the user is redirected to "Tools" menu.
// (otherwise, the function simply returns.)
function writeConfig()
{
    if (is_file($GLOBALS['config']['CONFIG_FILE']) && !isLoggedIn()) die('You are not authorized to alter config.'); // Only logged in user can alter config.
    if (empty($GLOBALS['redirector'])) $GLOBALS['redirector']='';
    $config='<?php $GLOBALS[\'login\']='.var_export($GLOBALS['login'],true).'; $GLOBALS[\'hash\']='.var_export($GLOBALS['hash'],true).'; $GLOBALS[\'salt\']='.var_export($GLOBALS['salt'],true).'; ';
    $config .='$GLOBALS[\'timezone\']='.var_export($GLOBALS['timezone'],true).'; date_default_timezone_set('.var_export($GLOBALS['timezone'],true).'); $GLOBALS[\'title\']='.var_export($GLOBALS['title'],true).';';
    $config .= '$GLOBALS[\'redirector\']='.var_export($GLOBALS['redirector'],true).'; ';
    $config .= ' ?>';    
    if (!file_put_contents($GLOBALS['config']['CONFIG_FILE'],$config) || strcmp(file_get_contents($GLOBALS['config']['CONFIG_FILE']),$config)!=0)
    {
        echo '<script language="JavaScript">alert("Shaarli could not create the config file. Please make sure Shaarli has the right to write in the folder is it installed in.");document.location=\'?\';</script>';
        exit;
    }
}

/* Because some f*cking services like Flickr require an extra HTTP request to get the thumbnail URL,
   I have deported the thumbnail URL code generation here, otherwise this would slow down page generation.
   The following function takes the URL a link (eg. a flickr page) and return the proper thumbnail.
   This function is called by passing the url:
   http://mywebsite.com/shaarli/?do=genthumbnail&hmac=[HMAC]&url=[URL]
   [URL] is the URL of the link (eg. a flickr page)
   [HMAC] is the signature for the [URL] (so that these URL cannot be forged).
   The function below will fetch the image from the webservice and store it in the cache.
*/
function genThumbnail()
{
    // Make sure the parameters in the URL were generated by us.
    $sign = hash_hmac('sha256', $_GET['url'], $GLOBALS['salt']);
    if ($sign!=$_GET['hmac']) die('Naughty boy !'); 
    
    // Let's see if we don't already have the image for this URL in the cache.
    $thumbname=hash('sha1',$_GET['url']).'.jpg';
    if (is_file($GLOBALS['config']['CACHEDIR'].'/'.$thumbname))
    {   // We have the thumbnail, just serve it:
        header('Content-Type: image/jpeg');
        echo file_get_contents($GLOBALS['config']['CACHEDIR'].'/'.$thumbname);  
        return;
    }
    // We may also serve a blank image (if service did not respond)
    $blankname=hash('sha1',$_GET['url']).'.gif';
    if (is_file($GLOBALS['config']['CACHEDIR'].'/'.$blankname))
    {
        header('Content-Type: image/gif');
        echo file_get_contents($GLOBALS['config']['CACHEDIR'].'/'.$blankname);  
        return;
    }    
    
    // Otherwise, generate the thumbnail.
    $url = $_GET['url'];
    $domain = parse_url($url,PHP_URL_HOST);

    if ($domain=='flickr.com' || endsWith($domain,'.flickr.com'))
    {    
        // WTF ? I need a flickr API key to get a fucking thumbnail ? No way.
        // I'll extract the thumbnail URL myself. First, we have to get the flickr HTML page.
        // All images in Flickr are in the form:
        // http://farm[farm].static.flickr.com/[server]/[id]_[secret]_[size].jpg
        // Example: http://farm7.static.flickr.com/6205/6088513739_fc158467fe_z.jpg
        // We want the 240x120 format, which is _m.jpg.
        // We search for the first image in the page which does not have the _s size,
        // when use the _m to get the thumbnail.

        // Is this a link to an image, or to a flickr page ?
        $imageurl='';
        if (endswith(parse_url($url,PHP_URL_PATH),'.jpg'))
        {  // This is a direct link to an image. eg. http://farm1.static.flickr.com/5/5921913_ac83ed27bd_o.jpg
            preg_match('!(http://farm\d+.static.flickr.com/\d+/\d+_\w+_)\w.jpg!',$url,$matches);
            if (!empty($matches[1])) $imageurl=$matches[1].'m.jpg';  
        }
        else // this is a flickr page (html)
        {
            list($httpstatus,$headers,$data) = getHTTP($url,20); // Get the flickr html page.
            if (strpos($httpstatus,'200 OK')!==false)
            {
                preg_match('!(http://farm\d+.static.flickr.com/\d+/\d+_\w+_)[^s].jpg!',$data,$matches);
                if (!empty($matches[1])) $imageurl=$matches[1].'m.jpg';  
            }
        }
        if ($imageurl!='')
        {   // Let's download the image.
            list($httpstatus,$headers,$data) = getHTTP($imageurl,10); // Image is 240x120, so 10 seconds to download should be enough.
            if (strpos($httpstatus,'200 OK')!==false)
            {
                file_put_contents($GLOBALS['config']['CACHEDIR'].'/'.$thumbname,$data); // Save image to cache.
                header('Content-Type: image/jpeg');
                echo $data;
                return;
            }
        } 
    }

    if ($domain=='vimeo.com' )
    {
        // This is more complex: we have to perform a HTTP request, then parse the result.
        // Maybe we should deport this to javascript ? Example: http://stackoverflow.com/questions/1361149/get-img-thumbnails-from-vimeo/4285098#4285098
        $vid = substr(parse_url($url,PHP_URL_PATH),1);
        list($httpstatus,$headers,$data) = getHTTP('http://vimeo.com/api/v2/video/'.htmlspecialchars($vid).'.php',5);
        if (strpos($httpstatus,'200 OK')!==false)
        {
            $t = unserialize($data);
            $imageurl = $t[0]['thumbnail_medium'];
            // Then we download the image and serve it to our client.
            list($httpstatus,$headers,$data) = getHTTP($imageurl,10);
            if (strpos($httpstatus,'200 OK')!==false)
            {
                file_put_contents($GLOBALS['config']['CACHEDIR'].'/'.$thumbname,$data); // Save image to cache.
                header('Content-Type: image/jpeg');
                echo $data;
                return;
            }           
        }  
    }
    
    // For all other domains, we try to download the image and make a thumbnail.
    list($httpstatus,$headers,$data) = getHTTP($url,30);  // We allow 30 seconds max to download (and downloads are limited to 4 Mb)
    if (strpos($httpstatus,'200 OK')!==false)
    {
        $filepath=$GLOBALS['config']['CACHEDIR'].'/'.$thumbname;
        file_put_contents($filepath,$data); // Save image to cache.
        if (resizeImage($filepath))
        {
            header('Content-Type: image/jpeg');
            echo file_get_contents($filepath);  
            return;
        }
    }  


    // Otherwise, return an empty image (8x8 transparent gif)
    $blankgif = base64_decode('R0lGODlhCAAIAIAAAP///////yH5BAEKAAEALAAAAAAIAAgAAAIHjI+py+1dAAA7');
    file_put_contents($GLOBALS['config']['CACHEDIR'].'/'.$blankname,$blankgif); // Also put something in cache so that this URL is not requested twice.
    header('Content-Type: image/gif');
    echo $blankgif;
}

// Make a thumbnail of the image (to width: 120 pixels)
// Returns true if success, false otherwise.
function resizeImage($filepath)
{
    if (!function_exists('imagecreatefromjpeg')) return false; // GD not present: no thumbnail possible.

    // Trick: some stupid people rename GIF as JPEG... or else.
    // So we really try to open each image type whatever the extension is.
    $header=file_get_contents($filepath,false,NULL,0,256); // Read first 256 bytes and try to sniff file type.
    $im=false;
    $i=strpos($header,'GIF8'); if (($i!==false) && ($i==0)) $im = imagecreatefromgif($filepath); // Well this is crude, but it should be enough.
    $i=strpos($header,'PNG'); if (($i!==false) && ($i==1)) $im = imagecreatefrompng($filepath);
    $i=strpos($header,'JFIF'); if ($i!==false) $im = imagecreatefromjpeg($filepath);
    if (!$im) return false;  // Unable to open image (corrupted or not an image)
    $w = imagesx($im);
    $h = imagesy($im);
    $ystart = 0; $yheight=$h;
    if ($h>$w) { $ystart= ($h/2)-($w/2); $yheight=$w/2; }
    $nw = 120;   // Desired width
    $nh = min(floor(($h*$nw)/$w),120); // Compute new width/height, but maximum 120 pixels height.
    // Resize image:
    $im2 = imagecreatetruecolor($nw,$nh);
    imagecopyresampled($im2, $im, 0, 0, 0, $ystart, $nw, $nh, $w, $yheight);
    imageinterlace($im2,true); // For progressive JPEG.
    $tempname=$filepath.'_TEMP.jpg';
    imagejpeg($im2, $tempname, 90);
    imagedestroy($im);
    imagedestroy($im2);
    rename($tempname,$filepath);  // Overwrite original picture with thumbnail.
    return true;
}

// Invalidate caches when the database is changed or the user logs out.
// (eg. tags cache).
function invalidateCaches()
{
    unset($_SESSION['tags']);
}

if (startswith($_SERVER["QUERY_STRING"],'do=genthumbnail')) { genThumbnail(); exit; }  // Thumbnail generation/cache does not need the link database.
$LINKSDB=new linkdb(isLoggedIn() || $GLOBALS['config']['OPEN_SHAARLI']);  // Read links from database (and filter private links if used it not logged in).
if (startswith($_SERVER["QUERY_STRING"],'ws=')) { processWS(); exit; } // Webservices (for jQuery/jQueryUI)
if (!isset($_SESSION['LINKS_PER_PAGE'])) $_SESSION['LINKS_PER_PAGE']=$GLOBALS['config']['LINKS_PER_PAGE'];
if (startswith($_SERVER["QUERY_STRING"],'do=rss')) { showRSS(); exit; }
if (startswith($_SERVER["QUERY_STRING"],'do=atom')) { showATOM(); exit; }
renderPage();
?>