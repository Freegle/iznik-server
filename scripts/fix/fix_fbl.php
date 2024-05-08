<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:');

$email = trim(Utils::presdef('e', $opts, NULL));

$u = new User($dbhr, $dbhm);
$uid = $u->findByEmail($email);

if ($uid) {
    $u = User::get($dbhr, $dbhm, $uid);
    $u->setSetting('simplemail', User::SIMPLE_MAIL_NONE);
    $u->FBL();
    error_log("Email off for $email");
} else {
    error_log("Failed to find user $email");
}

