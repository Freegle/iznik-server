<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/noticeboard/Noticeboard.php');

$lockh = lockScript(basename(__FILE__));

$boards = $dbhr->preQuery("SELECT * FROM noticeboards WHERE thanked IS NULL AND addedby IS NOT NULL AND active = 1 AND name IS NOT NULL;");
$users = [];

foreach ($boards as $board) {
    if (!pres($board['addedby'], $users)) {
        $n = new Noticeboard($dbhr, $dbhm, $board['id']);
        $n->thank($board['addedby'], $board['id']);
        $users[$board['addedby']] = TRUE;
    }
}

unlockScript($lockh);