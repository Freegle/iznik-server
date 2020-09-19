<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:');

if (count($opts) < 1) {
    echo "Usage: php user_decodeid.php -e <enc>\n";
} else {
    $id = User::decodeId($opts['e']);
    $u = new User($dbhr, $dbhm, $id);
    error_log("#$id email " . $u->getEmailPreferred());
}
