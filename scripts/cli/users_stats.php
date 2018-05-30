<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');

$groupid = 21589;

$users = $dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid = $groupid;");

$offers = [];
$wanteds = [];
$firstoffers = [];
$firstwanteds = [];

foreach ($users as $user) {
    $msgs = $dbhr->preQuery("SELECT id, type, arrival FROM messages WHERE fromuser = ? ORDER BY arrival ASC;", [
        $user['userid']
    ]);

    $count = 0;

    foreach ($msgs as $msg) {
        if ($msg['type'] == Message::TYPE_OFFER) {
            $offers[$user['userid']] = strtotime($msg['arrival']);

            if ($count == 0) {
                $firstoffers[$user['userid']] = strtotime($msg['arrival']);
            }
        } else {
            $wanteds[$user['userid']] = strtotime($msg['arrival']);

            if ($count == 0) {
                $firstwanteds[$user['userid']] = strtotime($msg['arrival']);
            }
        }

        $count++;
    }
}

# Now get the repliers.
$repliers = $dbhr->preQuery("SELECT DISTINCT chat_messages.userid FROM chat_messages INNER JOIN memberships ON memberships.userid = chat_messages.userid WHERE groupid = $groupid AND chat_messages.type = ?;", [
    ChatMessage::TYPE_INTERESTED
]);

$replyoffers = [];
$replywanteds = [];
$firstreplyoffers = [];
$firstreplywanteds = [];

foreach ($users as $user) {
    $chats = $dbhr->preQuery("SELECT chat_messages.userid, messages.type, messages.arrival FROM chat_messages INNER JOIN messages ON messages.id = chat_messages.refmsgid WHERE userid = ? AND chat_messages.type = ?;", [
        $user['userid'],
        ChatMessage::TYPE_INTERESTED
    ]);

    $count = 0;

    foreach ($chats as $chat) {
        if ($chat['type'] == Message::TYPE_OFFER) {
            $replyoffers[$chat['userid']] = strtotime($chat['arrival']);
        } else {
            $replywanteds[$chat['userid']] = strtotime($chat['arrival']);
        }

        if ($count == 0) {
            if ($chat['type'] == Message::TYPE_OFFER) {
                $firstreplyoffers[$chat['userid']] = strtotime($chat['arrival']);
            } else {
                $firstreplywanteds[$chat['userid']] = strtotime($chat['arrival']);
            }
        }

        $count++;
    }
}

# Now stats
error_log(count($offers) . " offers and " . count($wanteds) . " wanters");
$count = 0;

foreach ($wanteds as $userid => $val) {
    if (pres($userid, $offers)) {
        $count++;
    }
}

error_log("Of the " . count($wanteds) . " wanters, $count also offerers");

$count = 0;

foreach ($offers as $userid => $val) {
    if (pres($userid, $wanteds)) {
        $count++;
    }
}

error_log("Of the " . count($offers) . " offerers, $count also wanters");

error_log(count($firstoffers) . " first post OFFER, " . count($firstwanteds) . " first post WANTED");

$count = 0;

foreach ($firstoffers as $userid => $val) {
    if (pres($userid, $wanteds)) {
        $count++;
    }
}

error_log("Of the " . count($firstoffers) . " first post OFFERs, $count then posted a WANTED");

$count = 0;

foreach ($firstwanteds as $userid => $val) {
    if (pres($userid, $offers)) {
        $count++;
    }
}

error_log("Of the " . count($firstwanteds) . " first post WANTEDs, $count then posted an OFFER");

error_log(count($repliers). " repliers of whom " . count($replyoffers) . " replied to OFFERs and " . count($replywanteds) . " replied to WANTEDs");
error_log(count($firstreplyoffers) . " first replied to an OFFER, and " . count($firstreplywanteds) . " to a WANTED");

$count = 0;

foreach ($firstreplyoffers as $userid => $time) {
    if (pres($userid, $offers) > $time) {
        $count++;
    }
}

error_log("Of the " . count($firstreplyoffers) . " first reply to OFFER, $count then posted an OFFER");

$count = 0;

foreach ($firstreplywanteds as $userid => $time) {
    if (pres($userid, $wanteds) > $time) {
        $count++;
    }
}

error_log("Of the " . count($firstreplywanteds) . " first reply to WANTED, $count then posted a WANTED");

$wanters = array_unique(array_merge(array_keys($replyoffers), array_keys($wanteds)));
$offerers = array_unique(array_merge(array_keys($replywanteds), array_keys($offers)));

error_log(count($offerers) . " offerers and " . count($wanteds) . " wanters both " . count(array_intersect($offerers, $wanters)));
