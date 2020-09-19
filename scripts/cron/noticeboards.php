<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$boards = $dbhr->preQuery("SELECT * FROM noticeboards WHERE thanked IS NULL AND addedby IS NOT NULL AND active = 1 AND name IS NOT NULL;");
$users = [];

foreach ($boards as $board) {
    if (!Utils::pres($board['addedby'], $users)) {
        $n = new Noticeboard($dbhr, $dbhm, $board['id']);
        $n->thank($board['addedby'], $board['id']);
        $users[$board['addedby']] = TRUE;
    }
}

$n = new Noticeboard($dbhr, $dbhm);
$n->chaseup();

Utils::unlockScript($lockh);