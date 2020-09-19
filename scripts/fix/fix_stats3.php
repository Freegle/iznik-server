<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/misc/Stats.php');

for ($i = 1; $i < 1620; $i++) {
    $epoch = strtotime("$i days ago");
    $date = date('Y-m-d', $epoch);
    error_log($date);

    if ($epoch < strtotime('2015-08-24')) {
        break;
    }

    $groups = $dbhr->preQuery("SELECT * FROM groups WHERE type = 'Freegle' ORDER BY nameshort ASC;");
    foreach ($groups as $group) {
        #error_log("...{$group['nameshort']}");
        $s = new Stats($dbhr, $dbhm, $group['id']);
        $s->generate($date, [Stats::REPLIES, Stats::SEARCHES, Stats::ACTIVITY, Stats::APPROVED_MESSAGE_COUNT]);
    }
}
