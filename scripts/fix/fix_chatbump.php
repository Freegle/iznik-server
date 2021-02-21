<?php
# Rescale large images in message_attachments

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$r = new ChatRoom($dbhr, $dbhm);
$chats = $r->listForUser(FALSE, 39023744, NULL, NULL, NULL, 365);

foreach ($chats as $chat) {
    error_log($chat);
    $r->ensureAppearInList($chat);
}
