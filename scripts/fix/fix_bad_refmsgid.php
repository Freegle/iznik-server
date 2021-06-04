<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery("SELECT id FROM chat_messages WHERE refmsgid IS NOT NULL and type = 'Default'");
$total = count($msgs);
error_log("Found $total");

$count = 0;

foreach ($msgs as $msg) {
    $dbhm->preExec("UPDATE chat_messages SET refmsgid = NULL WHERE id = ?;", [
        $msg['id']
    ]);

    $count++;

    if ($count % 1000 == 0){
        error_log("$count / $total");
    }
}

# Now purge messages which are stranded, not on any groups and not referenced from any chats or drafts.
$start = date('Y-m-d', strtotime("midnight 2 days ago"));
error_log("Purge stranded messages before $start");
$total = 0;

do {
    $sql = "SELECT messages.id FROM messages LEFT JOIN messages_groups ON messages_groups.msgid = messages.id LEFT JOIN chat_messages ON chat_messages.refmsgid = messages.id LEFT JOIN messages_drafts ON messages_drafts.msgid = messages.id WHERE messages.arrival <= '$start' AND messages_groups.msgid IS NULL AND chat_messages.refmsgid IS NULL AND messages_drafts.msgid IS NULL LIMIT 1000;";
    $msgs = $dbhr->query($sql);
    $count = 0;

    foreach ($msgs as $msg) {
        #error_log("...{$msg['id']}");
        $sql = "DELETE FROM messages WHERE id = {$msg['id']};";
        $count = $dbhm->preExec($sql);
        $total++;

        if ($total % 1000 == 0) {
            error_log("...$total");
        }
    }
} while ($count > 0);

error_log("Deleted $total");
