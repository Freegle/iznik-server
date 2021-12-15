<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$dbhback = new LoggedPDO('localhost:3309', $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE);

error_log("Fetch locations");
$areas = $dbhback->preQuery("SELECT id, areaid FROM locations WHERE locations.type = 'Postcode' AND locate(' ', locations.name) > 0;");
$total = count($areas);
$count = 0;
$different = 0;
$same = 0;

error_log("Update");

foreach ($areas as $area) {
    $existings = $dbhr->preQuery("SELECT areaid FROM locations WHERE id = ?;", [
        $area['id']
    ]);

    foreach ($existings as $existing) {
        if ($existing['areaid'] != $area['areaid']) {
            $dbhm->preExec("UPDATE locations SET areaid = ? WHERE id = ?;", [
                $area['areaid'],
                $area['id']
            ]);

            $different++;
        } else {
            $same++;
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("$count / $total, same $same different $different");
    }
}
