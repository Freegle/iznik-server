<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# You can get this token using the graph explorer - select the Freegle Graffiti app, then get a Page token for the
# Freegle page.
#
# Once you get that, you can run this script, and it will output the long-lived token.  That then needs to
# go into the crontab invocation, and also into the CircleCI FACEBOOK_PAGEACCESS_TOKEN environment variable.
$opts = getopt('t:');
$token = $opts['t'];

error_log("Start at " . date("Y-m-d H:i:s"));

# Get the posts we want to offer mods to share.  This is a bit inefficient, but it's a background process.
$sharefroms = $dbhr->preQuery("SELECT DISTINCT sharefrom FROM groups_facebook;");

foreach ($sharefroms as $sharefrom) {
    $f = new Facebook($dbhr, $dbhm);
    $fg = new GroupFacebook($dbhr, $dbhm);
    $fb = $fg->getFB(TRUE, FALSE);
    $longLived = $f->getLongLivedToken($fb, $token, TRUE);
    $fg->getPostsToShare($sharefrom['sharefrom'], "last week", $longLived);
    error_log("\n\nLong-lived token $longLived");
}

error_log("Finish at " . date("Y-m-d H:i:s"));

Utils::unlockScript($lockh);