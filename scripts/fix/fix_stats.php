<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/misc/Stats.php');

    $date = '2019-10-13';
    error_log($date);

    $groups = $dbhr->preQuery("SELECT * FROM groups WHERE type = 'Freegle' ORDER BY nameshort ASC;");
    foreach ($groups as $group) {
        error_log("...{$group['nameshort']}");
        $s = new Stats($dbhr, $dbhm, $group['id']);
        $s->generate($date);
    }
