<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$opts = getopt('t:');
$token = $opts['t'];

error_log("Start at " . date("Y-m-d H:i:s"));

# Get the posts we want to offer mods to share.  This is a bit inefficient, but it's a background process.
$sharefroms = $dbhr->preQuery("SELECT DISTINCT sharefrom FROM groups_facebook;");

foreach ($sharefroms as $sharefrom) {
    # We can create the app access token from app_id|app_secret.
#    $token = FBGRAFFITIAPP_ID . '|' . FBGRAFFITIAPP_SECRET;
    $f = new Facebook($dbhr, $dbhm);
    $fg = new GroupFacebook($dbhr, $dbhm);
    $fb = $fg->getFB(TRUE, FALSE);
    $longLived = $f->getLongLivedToken($fb, $token, TRUE);
    $fg->getPostsToShare($sharefrom['sharefrom'], "last week", $longLived);
    error_log("\n\nLong-lived token $longLived");
}

error_log("Finish at " . date("Y-m-d H:i:s"));

Utils::unlockScript($lockh);