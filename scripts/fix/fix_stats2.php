<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$groups = $dbhr->preQuery("SELECT * FROM groups  WHERE type = 'Freegle' ORDER BY nameshort ASC;");
foreach ($groups as $group) {
    error_log("...{$group['nameshort']}");
    $epoch = strtotime("today");

    for ($i = 0; $i < 300; $i++) {
        $date = date('Y-m-d', $epoch);
        $s = new Stats($dbhr, $dbhm, $group['id']);
        $s->generate($date, [Stats::ACTIVE_USERS]);
        $epoch -= 24 * 60 * 60;
    }
}
