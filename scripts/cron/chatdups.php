<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Rescale large images in message_attachments

$lockh = Utils::lockScript(basename(__FILE__));

$mysqltime = date("Y-m-d", strtotime("Midnight 3 days ago"));
$chats = $dbhr->preQuery("SELECT id FROM chat_rooms;");

foreach ($chats as $chat) {
    $chatmsgs = $dbhr->preQuery("SELECT * FROM chat_messages WHERE date > ? AND chatid = ?;", [ $mysqltime, $chat['id'] ]);

    $lastmsg = NULL;
    $lastref = NULL;
    $lastid = NULL;

    foreach ($chatmsgs as $msg) {
        if ($lastmsg && $lastmsg == $msg['message'] && $lastref == $msg['refmsgid']) {
            error_log("{$chat['id']} $lastid and {$msg['id']}");
            $dbhm->preExec("DELETE FROM chat_messages WHERE id = ?;", [ $msg['id'] ]);
        } else {
            $lastmsg = $msg['message'];
            $lastref = $msg['refmsgid'];
            $lastid = $msg['id'];
        }
    }
}

Utils::unlockScript($lockh);