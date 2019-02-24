<?php

# Exhort active users to do something via onsite notifications.

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/user/Notifications.php');

$opts = getopt('u:l:x:s:');

if (count($opts) < 4) {
    echo "Usage: php user_exhort.php -u url -l title -x text -s since\n";
} else {
    $url = presdef('u', $opts, NULL);
    $title = presdef('l', $opts, NULL);
    $text = presdef('x', $opts, NULL);
    $since = presdef('s', $opts, NULL);
    $until = presdef('t', $opts, NULL);

    $n = new Notifications($dbhr, $dbhm);
    $u = new User($dbhr, $dbhm);

    error_log("Get active since $since -> $until");
    $ids = $u->getActiveSince($since, $until);
    $total = count($ids);
    $sent = 0;
    $at = 0;
    error_log("...got " . count($ids));

    foreach ($ids as $uid) {
        if ($n->haveSent($uid, Notifications::TYPE_EXHORT, "30 days ago")) {
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
