<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$l = new LoveJunk($dbhr, $dbhm);

$start = date("Y-m-d", strtotime("24 hours ago"));

$msgs = $dbhr->preQuery("SELECT messages.id FROM messages 
    LEFT JOIN lovejunk ON lovejunk.msgid = messages.id
    INNER JOIN messages_groups ON messages_groups.msgid = messages.id 
    WHERE messages.arrival >= ? AND
          messages.arrival >= '2023-06-13 14:50' AND
      messages.type = ? AND   
      lovejunk.msgid IS NULL AND
      messages_groups.collection = ?
      ORDER BY messages.arrival ASC;
", [
    $start,
    Message::TYPE_OFFER,
    MessageCollection::APPROVED
]);

foreach ($msgs as $msg) {
    error_log($msg['id']);
    $l->send($msg['id']);
}

Utils::unlockScript($lockh);