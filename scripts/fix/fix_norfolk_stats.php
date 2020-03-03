<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/misc/Stats.php');

$groups = $dbhr->preQuery("SELECT * FROM groups  WHERE id IN (515504,
515507,
515510,
515513,
515516,
515519,
515522,
515525,
515528,
515531,
515534,
515537,
515540,
515543,
515546,
515549,
515552) ORDER BY nameshort ASC;");

foreach ($groups as $group) {
    $epoch = strtotime("today");

    for ($i = 0; $i < 2000; $i++) {
        $date = date('Y-m-d', $epoch);
        error_log("...{$group['nameshort']} $date");

        if ($epoch < strtotime('2015-08-24')) {
            break;
        }

        $s = new Stats($dbhr, $dbhm, $group['id']);
        $s->generate($date);
        $epoch -= 24 * 60 * 60;
    }
}
