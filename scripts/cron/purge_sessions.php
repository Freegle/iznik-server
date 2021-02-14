<?php
#
# Purge user sessions. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$start = date('Y-m-d', strtotime("midnight 31 days ago"));
$total = 0;
do {
    $count = $dbhm->exec("DELETE FROM sessions WHERE `lastactive` < '$start' LIMIT 1000;");
    $total += $count;
    error_log("...$total");
} while ($count > 0);

