<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

$messages = $dbhr->preQuery("SELECT DISTINCT msgid FROM messages_postings WHERE autorepost = 1 AND DATE >  '2017-12-01' AND msgid = 37193262;");

foreach ($messages as $message) {
    $m = new Message($dbhr, $dbhm, $message['msgid']);
    $groups = $m->getGroups(TRUE, TRUE);

    if (count($groups) == 0) {
        error_log("{$message['msgid']}");
    }
}