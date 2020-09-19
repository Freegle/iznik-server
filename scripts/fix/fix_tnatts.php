<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
require_once dirname(__FILE__) . '/../../include/config.php';

require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

$start = date('Y-m-d', strtotime("midnight 31 days ago"));

error_log("Query");
$msgs = $dbhr->preQuery("SELECT messages.id, messages.date FROM messages LEFT JOIN messages_attachments ON messages_attachments.msgid = messages.id WHERE date >= '2020-07-01' AND fromaddr LIKE '%trashnothing%' AND message IS NOT NULL AND type IN ('Offer', 'Wanted') AND messages_attachments.msgid IS NULL ORDER BY messages.date DESC;");
$count = count($msgs);
error_log("Got $count");

$at = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['id']);
    $m->parse($m->getSource(), $m->getEnvelopefrom(), $m->getEnvelopeto(), $m->getPrivate('message'));

    if (count($m->getAttachments()) == 0) {
        $m->scrapePhotos();
        $now = count($m->getParsedAttachments()) + count($m->getInlineimgs());

        if ($now) {
            error_log("{$msg['id']} {$msg['date']}" . $m->getSubject() . "...missing $now");
            $m->saveAttachments($msg['id']);
        }
    }

    $at++;

    if ($at % 1000 === 0) {
        error_log("...$at / $count");
    }
}
