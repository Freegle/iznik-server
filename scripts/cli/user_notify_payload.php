<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:');

if (count($opts) < 1) {
    echo "Usage: php user_notify_payload.php -e <email>\n";
} else {
    $email = $opts['e'];

    $u = new User($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if ($uid) {
        $u = new User($dbhr, $dbhm, $uid);
        error_log(var_export($u->getNotificationPayload(FALSE), TRUE));
    } else {
        error_log("User not found");
    }
}
