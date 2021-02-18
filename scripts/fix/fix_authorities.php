<?php
# Some authority data is MULTIPOLYGON.  This causes problems with some of the MySQL geometry functions - probably
# bugs.  So find such multipolygons and use the largest of its multipolygons instead.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$i = 0;

$auths = $dbhr->preQuery("SELECT id FROM authorities;");

foreach ($auths as $auth) {
    $i++;

    if ($i % 100 == 0) {
        error_log($i);
    }

    try {
        $dbhm->preExec("UPDATE authorities SET simplified = ST_Simplify(polygon, 0.001) WHERE id = ?;", [
            $auth['id']
        ]);
    } catch (\Exception $e) {
        error_log("Failed " . $e->getMessage() . " {$auth['id']}");
    }
}