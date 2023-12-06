<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT id FROM users WHERE marketingconsent = 1");
$total = count($users);
$count = 0;

foreach ($users as $user) {
    $dbhm->preExec("UPDATE users SET marketingconsent = 0 WHERE id = ?", [ $user['id'] ]);

    $count++;
    if ($count % 1000 == 0) {
        error_log("Processed $count of $total");
    }
}