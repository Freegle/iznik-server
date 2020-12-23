<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$views = $dbhr->preQuery("SELECT messages_items.itemid, userid FROM messages_likes INNER JOIN messages_items ON messages_items.msgid = messages_likes.msgid WHERE messages_likes.type = ? ORDER BY messages_likes.userid, messages_likes.msgid ASC LIMIT 1000;", [
    Message::LIKE_VIEW
]);

foreach ($views as $view) {
    echo "{$view['userid']}, {$view['itemid']}\n";
}