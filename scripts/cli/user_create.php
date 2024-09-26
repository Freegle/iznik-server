<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:n:p:');

if (count($opts) < 2) {
    echo "Usage: php user_create.php -e <email> -n <full name> (-p <password>)\n";
} else {
    $email = Utils::presdef('e', $opts, NULL);
    $name = Utils::presdef('n', $opts, NULL);
    $password = Utils::presdef('p', $opts, NULL);

    $u = User::get($dbhr, $dbhm);

    if (!$password) {
        $password = $u->inventPassword();
        error_log("Use password $password");
        sleep(5);
    }

    $u = User::get($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if ($uid) {
        error_log("User already exists for $email");
    } else {
        $uid = $u->create(NULL, NULL, $name);
        $u->addEmail($email);
        $u->addLogin(User::LOGIN_NATIVE, NULL, $password);
        $u->welcome($email, $password);
        error_log("Created ". $uid);
    }
}
