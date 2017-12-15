<?php
header('Access-Control-Allow-Origin: *');
date_default_timezone_set('UTC');
session_start();
define( 'BASE_DIR', dirname(__FILE__) . '/..' );
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/session/Yahoo.php');
require_once(IZNIK_BASE . '/include/session/Facebook.php');
require_once(IZNIK_BASE . '/include/session/Google.php');
require_once(IZNIK_BASE . '/include/session/Session.php');
require_once(IZNIK_BASE . '/include/user/User.php');

if (!defined('SITE_NAME')) { error_log("Bad config " . $_SERVER['HTTP_HOST']); }

global $dbhr, $dbhm;

if (pres('REQUEST_URI', $_SERVER) == 'yahoologin') {
    # We have been redirected here from Yahoo.  Time to try to log in while we still have the
    # OAUTH data in our parameters (which won't be the case on subsequent API calls).
    #error_log("Redirect from Yahoo");
    $y = new Yahoo($dbhr, $dbhm);

    # No need to pay attention to the result - whether it worked or not will be determined by the
    # client later.
    $y->login(get_current_url());
} else if (pres('fblogin', $_REQUEST)) {
    # We are logging in using Facebook, but on the server because of a problem with Chrome on IOS - see
    # signinup.js
    $fbcode = presdef('code', $_REQUEST, NULL);
    $f = new Facebook($dbhr, $dbhm);
    $url = get_current_url();
    $url = substr($url, 0, strpos($url, '&code'));
    $f->login(NULL, $fbcode, $url);

    # Now redirect so that the code doesn't appear in the URL to the user, which looks messy.
    $url = substr($url, 0, strpos($url, '?'));
    header("Location: " . $url);
    exit(0);
} else if (pres('googlelogin', $_REQUEST)) {
    # We are logging in using Google.  We always do server logins for google due to issues with multiple accounts -
    # see google.js for more details.
    $code = presdef('code', $_REQUEST, NULL);
    $g = new Google($dbhr, $dbhm, FALSE);
    $url = get_current_url();
    $url = substr($url, 0, strpos($url, '&code'));
    $client = $g->getClient();
    $client->setRedirectUri($url);

    $g->login($code);

    # Now redirect so that the code doesn't appear in the URL to the user, which looks messy.
    $url = substr($url, 0, strpos($url, '?'));
    header("Location: " . $url);
    exit(0);
} else if (pres('fb_locale', $_REQUEST) && pres('signed_request', $_REQUEST)) {
    # Looks like a load of the Facebook app.
    $f = new Facebook($dbhr, $dbhm);
    $f->loadCanvas();
}

# Depending on rewrites we might not have set up $_REQUEST.
if (strpos($_SERVER['REQUEST_URI'], '?') !== FALSE) {
    list($path, $qs) = explode("?", $_SERVER["REQUEST_URI"], 2);
    parse_str($qs, $qss);
    $_REQUEST = array_merge($_REQUEST, $qss);
}

if (!pres('id', $_SESSION)) {
    # Not logged in.  Check if we are fetching this url with a key which allows us to auto-login a user.
    $uid = presdef('u', $_REQUEST, NULL);
    $key = presdef('k', $_REQUEST, NULL);
    if ($uid && $key) {
        $u = User::get($dbhr, $dbhm, $uid);
        $u->linkLogin($key);
    }
}

if (pres('src', $_REQUEST)) {
    $dbhm->preExec("INSERT INTO logs_src (src, userid, session) VALUES (?, ?, ?);", [
        $_REQUEST['src'],
        presdef('id', $_SESSION, NULL),
        session_id()
    ]);

    # Record in the session, as we might later create a user.
    $_SESSION['src'] = $_REQUEST['src'];
}

# Server-side rendering.  The webpack build produces an index.html which will
# run the app, but we need to be able to serve up real HTML for web crawlers (even Google is
# not yet reliable to properly index single-page apps).  We have a cron prerender script which
# does this.
#
# So here we look at the URL and see if we have a pre-rendered <body> in the DB; if so then we
# use that.  Otherwise we just use what's in index.html.
$prerender = NULL;

#error_log("Consider pre-render " . presdef('id', $_SESSION, 'no id'));

if (!pres('id', $_SESSION) && !pres('nocache', $_REQUEST)) {
    $url = "https://" . $_SERVER['HTTP_HOST'] . presdef('REQUEST_URI', $_SERVER, '');

    # If we are on the development (aka debug) or staging (aka dev) sites then pre-render the
    # corresponding info from the live site.
    # TODO Enable this once webpack is live.
    #$url = str_replace('https://iznik.', 'https://www.', $url);
    #$url = str_replace('https://dev.', 'https://www.', $url);

    #error_log("Check for pre-render $url");
    $prerenders = $dbhr->preQuery("SELECT * FROM prerender WHERE url = ?;", [ $url ]);

    if (count($prerenders) > 0 && $prerenders[0]['html']) {
        $prerender = $prerenders[0];
    }
}

if ($prerender) {
    #error_log("Pre-render $url");
    $head = $prerender['head'];
    $body = $prerender['html'];
    echo "<!DOCTYPE HTML><html><head>$head</head>$body</html>";
} else {
    #error_log("No pre-render");
    $indexhtml = file_get_contents('./index.html');
    echo $indexhtml;
}
?>