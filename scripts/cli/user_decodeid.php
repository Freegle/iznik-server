<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$opts = getopt('e:');

if (count($opts) < 1) {
    echo "Usage: php user_decodeid.php -e <enc>\n";
} else {
    $id = User::decodeId($opts['e']);
    $u = new User($dbhr, $dbhm, $id);
    error_log("#$id email " . $u->getEmailPreferred());
}
