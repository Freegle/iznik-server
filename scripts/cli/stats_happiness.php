<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$stats = [];

$res = $dbhm->query("SELECT * FROM messages_outcomes WHERE happiness IS NOT NULL ORDER BY timestamp ASC");

foreach ($res as $r) {
    $date = substr($r['timestamp'], 0, 7);

    if (!array_key_exists($date, $stats)) {
        $stats[$date] = [
            'Happy' => 0,
            'Unhappy' => 0,
            'Fine' => 0
        ];
    }

    $stats[$date][$r['happiness']]++;
}

foreach ($stats as $date => $s) {
    $pc = ($s['Unhappy'] / ($s['Happy'] + $s['Unhappy'] + $s['Fine'])) * 100;
    echo ("$date-01, $pc\n");
}


foreach ($stats as $date => $s) {
    $pc = ($s['Happy'] / ($s['Happy'] + $s['Unhappy'] + $s['Fine'])) * 100;
    echo ("$date-01, $pc\n");
}