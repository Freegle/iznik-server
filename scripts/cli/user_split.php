<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:n:a');

if (count($opts) < 2) {
    echo "Usage: php user_split.php -e <email to split out into separate user> -n <name> -a <allowmerge>\n";
} else {
    $email = $opts['e'];
    $name = $opts['n'];
    $allowmerge = intval($opts['a']);;
    $u = User::get($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if ($uid) {
        $u = User::get($dbhr, $dbhm, $uid);
        error_log("Found user #$uid");
        $uid2 = $u->split($email, $name);
        error_log("Split into #$uid2");

        $settings = $u->getPublic()['settings'];
        $settings['canmerge'] = $allowmerge;
        $u->setPrivate('settings', json_encode($settings));
    }
}
