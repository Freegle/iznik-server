<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT email FROM spam_users INNER JOIN users_emails ON users_emails.userid = spam_users.userid AND collection = 'Spammer' ORDER BY spam_users.added DESC LIMIT 1000");
$present = 0;

foreach ($users as $user) {
    $rsp = file_get_contents("https://api.stopforumspam.org/api?email={$user['email']}");

    if (strpos($rsp, 'appears>yes<') !== FALSE) {
        error_log("{$user['email']} appears");
        $present++;
        exit(0);
    } else {
        error_log("{$user['email']} does not appear");
    }
}

error_log("Found $present of " . count($users));
