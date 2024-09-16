<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:n:p:');

if (count($opts) < 2) {
    echo "Usage: php user_welcome.php -e <email> -p <password>\n";
} else {
    $email = Utils::presdef('e', $opts, NULL);
    $password = Utils::presdef('p', $opts, NULL);

    $u = User::get($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if (!$uid) {
        error_log("No user found");
    } else {
        $u = new User($dbhr, $dbhm, $uid);
        $u->welcome($email, $password);
        error_log("Welcome email sent to $email");
    }
}
