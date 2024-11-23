<?php

# Exhort active users to do something via onsite notifications.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$opts = getopt('u:l:x:s:t:i:');

if (count($opts) < 4) {
    echo "Usage: php user_exhort.php -u url -l title -x text -s since (-i uid)\n";
} else {
    $url = Utils::presdef('u', $opts, NULL);
    $title = Utils::presdef('l', $opts, NULL);
    $text = Utils::presdef('x', $opts, NULL);
    $since = Utils::presdef('s', $opts, NULL);
    $until = Utils::presdef('t', $opts, NULL);
    $touid = Utils::presdef('i', $opts, NULL);

    $n = new Notifications($dbhr, $dbhm);
    $u = new User($dbhr, $dbhm);

    error_log("Get active since $since -> $until");
    $ids = $u->getActiveSince($since, $until, $touid);
    $total = count($ids);
    $sent = 0;
    $at = 0;
    error_log("...got " . count($ids));

    foreach ($ids as $uid) {
        if (!$touid && $n->haveSent($uid, Notifications::TYPE_EXHORT, "90 days ago")) {
            #error_log("...already sent to $uid");
        } else {
            #error_log("...send to $uid");
            $n->add(NULL, $uid, Notifications::TYPE_EXHORT, NULL, NULL, $url, $title, $text);
            $sent++;
        }

        $at++;

        if ($at % 100 === 0) {
            error_log("...$at / $total");
        }
    }

    error_log("\n\nSent $sent");
}

Utils::unlockScript($lockh);