<?php
# Rescale large images in message_attachments

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$opts = getopt('i:t:m:u:');

if (count($opts) < 4) {
    echo "Usage: php chat_message_create.php -i <chat id> -u <uid> -t <message type> -m <text>\n";
} else {
    $id = Utils::presdef('i', $opts, null);
    $uid = Utils::presdef('u', $opts, null);
    $type = Utils::presdef('t', $opts, null);
    $message = Utils::presdef('m', $opts, null);

    $m = new ChatMessage($dbhr, $dbhm);
    $m->create($id, $uid, $message, $type, NULL, FALSE);
}
