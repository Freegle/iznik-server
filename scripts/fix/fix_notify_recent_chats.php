<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# This is a fallback, so it's only run occasionally through cron.
#
# Ensure the counts are correct in the chat_room.  This is no longer relevant to FD because of the Go API, but
# is relevant to MT.
$mysqltime = date("Y-m-d", strtotime("31 days ago"));
$chatids = $dbhr->preQuery("SELECT DISTINCT chatid FROM chat_messages INNER JOIN chat_rooms ON chatid = chat_rooms.id WHERE date >= ? AND msgvalid > 0;", [ $mysqltime ]);

$total = count($chatids);
$count = 0;
$unread = 0;

foreach ($chatids as $chatid) {
    $r = new ChatRoom($dbhr, $dbhm, $chatid['chatid']);
    $u1id = $r->getPrivate('user1');
    $u2id = $r->getPrivate('user2');

    $u1 = $u1id ? new User($dbhr, $dbhm, $u1id) : null;
    $u2 = $u2id ? new User($dbhr, $dbhm, $u2id) : null;

    $payload1 = $u1 ? $u1->getNotificationPayload(false) : null;
    $payload2 = $u2 ? $u2->getNotificationPayload(false) : null;

    if ($payload1 && $payload1[1] > 0) {
        error_log("...notify members of {$r->getId()} because of user1 $u1id count {$payload1[1]} {$payload1[3]}");
        $unread++;
        $r->notifyMembers();
    } else if ($payload2 && $payload2[1] > 0) {
        error_log("...notify members of {$r->getId()} because of user2 $u2id count {$payload2[1]} {$payload2[3]}");
        $unread++;
        $r->notifyMembers();
    }

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}

error_log("Notified for unread $unread");