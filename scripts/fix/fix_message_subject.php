<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery("SELECT messages.id, groupid, subject FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid WHERE messages.arrival >= '2021-12-08' AND source = 'Platform' AND locationid IS NOT NULL;");
error_log(count($msgs) . " to scan");
$different = 0;

foreach ($msgs as $msg) {
    $messageid = $msg['id'];
    $groupid = $msg['groupid'];
    $m = new Message($dbhr, $dbhm, $messageid);
    $suggested = $m->constructSubject($groupid, FALSE);
    if (strcmp(strtolower($suggested), strtolower($m->getPrivate('subject'))))
    {
        error_log("#$messageid on $groupid {$msg['subject']} => $suggested");
        $m->setPrivate('subject', $suggested);
        $different++;
    }
}

error_log("$different changed out of " . count($msgs));

