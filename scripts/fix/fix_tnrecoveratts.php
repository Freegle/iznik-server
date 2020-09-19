<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
require_once dirname(__FILE__) . '/../../include/config.php';

require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

$fh = fopen('/tmp/fdphotos.csv', 'r');
$at = 0;
$count = 142533;
$missing = 0;

if ($fh) {
    $l = new Location($dbhr, $dbhm);
    while (!feof($fh)) {
        $fields = fgetcsv($fh);
        $id = $fields[0];
        $url = $fields[1];

        $counts = $dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_attachments WHERE msgid = ?;", [
            $id
        ]);

        if ($counts[0]['count'] == 0) {
            $m = new Message($dbhr, $dbhm, $id);

            if (count($m->getAttachments()) == 0) {
                error_log("$id " . $m->getPrivate('date') . " " . $m->getSubject() . "...missing");
                $missing++;
//            $m->scrapePhotos();
//            $now = count($m->getParsedAttachments()) + count($m->getInlineimgs());
//
//            if ($now) {
//                error_log("{$msg['id']} {$msg['date']}" . $m->getSubject() . "...missing $now");
//                $m->saveAttachments($msg['id']);
//            }
            }
        }

        $at++;

        if ($at % 100 === 0) {
            error_log("...$at ($missing) / $count");
        }
    }
}
