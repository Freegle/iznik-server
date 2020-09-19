<?php

# Send test digest to a specific user
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:g:i:');

if (count($opts) < 3) {
    echo "Usage: php user_digest.php -e <email> -g <groupid> -i <interval>\n";
} else {
    $email = $opts['e'];
    $gid = intval($opts['g']);
    $interval = intval($opts['i']);

    $u = new User($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if ($gid && $uid) {
        $groups = $dbhr->preQuery("SELECT id, nameshort FROM groups WHERE id = ?;", [ $gid ]);
        $d = new Digest($dbhr, $dbhm, FALSE, TRUE);
        $d->errorLog = true;

        foreach ($groups as $group) {
            # Force
            $total = $d->send($group['id'], $interval, 'localhost', $uid);
        }

        error_log("Sent $total");
    }
}