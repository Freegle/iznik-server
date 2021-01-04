<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$dbhback = new LoggedPDO('localhost:3309', $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE);

$authorities = $dbhback->preQuery("SELECT id, AsText(simplified) AS simp FROM authorities WHERE simplified IS NOT NULL;");

error_log("Got " . count($authorities));
$count = 0;

foreach ($authorities as $a) {
    $count++;
    error_log($count);

    $dbhm->preExec("UPDATE authorities SET simplified = GeomFromText(?) WHERE id = ?;", [
        $a['id'],
        $a['simp']
    ]);
}