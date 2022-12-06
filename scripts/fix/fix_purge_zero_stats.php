<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

# Delete all zero count rows from the stats table in batches
$sql = "SELECT * FROM stats WHERE count = 0;";
$stats = $dbhr->preQuery($sql);
$count = 0;

foreach ($stats as $stat) {
    $dbhm->preExec("DELETE FROM stats WHERE id = ?;", [
        $stat['id']
    ]);

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count");
    }
}