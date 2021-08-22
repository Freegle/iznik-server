<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:');

if (count($opts) < 1) {
    echo "Usage: php newsfeed_count_unseen.php -e <email to find>\n";
} else {
    $find = Utils::presdef('e', $opts, NULL);
    $u = User::get($dbhr, $dbhm);
    $uid = $u->findByEmail($find);

    if ($uid) {
        $n = new Newsfeed($dbhr, $dbhm);
        error_log("Count is " . $n->getUnseen($uid));
    } else {
        error_log("Couldn't find user for $find");
    }
}
