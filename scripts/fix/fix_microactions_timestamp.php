<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$dbhback = new LoggedPDO('localhost:3309', $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE);

$vols = $dbhback->preQuery("SELECT * FROM microactions;");

foreach ($vols as $vol) {
    $dbhm->preExec("UPDATE microactions SET timestamp = ? WHERE id = ?", [
        $vol['timestamp'],
        $vol['id']
    ]);
}

