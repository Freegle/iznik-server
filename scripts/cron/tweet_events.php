<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

error_log("Start at " . date("Y-m-d H:i:s"));

$groups = $dbhr->preQuery("SELECT * FROM groups INNER JOIN groups_twitter ON groups.id = groups_twitter.groupid WHERE type = 'Freegle' AND publish = 1 AND valid = 1 ORDER BY LOWER(nameshort) ASC;");
foreach ($groups as $group) {
    $t = new Twitter($dbhr, $dbhm, $group['id']);
    $count = $t->tweetEvents();

    if ($count > 0) {
        error_log("{$group['nameshort']} $count");
    }
}

error_log("Finish at " . date("Y-m-d H:i:s"));

unlockScript($lockh);