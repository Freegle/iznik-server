<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$neatusers = [];
$threshold = 100;

$mysqltime = date ("Y-m-d", strtotime("Midnight 1 year ago"));

$users = $dbhr->preQuery("SELECT DISTINCT(fromuser) FROM messages 
    INNER JOIN users ON messages.fromuser = users.id 
                          WHERE arrival >= ? AND messages.type = ? AND messages.fromaddr LIKE '%users.ilovefreegle.org';", [
    $mysqltime,
    Message::TYPE_OFFER,
]);

error_log("Found " . count($users) . " FD users posted OFFERs since $mysqltime");

foreach ($users as $user) {
    $outcomes = $dbhr->preQuery("SELECT 
    CASE WHEN outcome IS NULL OR (outcome IS NOT NULL AND outcome = 'Withdrawn' AND comments = 'Auto-expired') THEN 'Expired'
    ELSE outcome END
    AS outcome, COUNT(messages.id) AS msgcount FROM messages 
        LEFT JOIN messages_outcomes ON messages.id = messages_outcomes.msgid 
        WHERE fromuser = ?AND messages.type = ? GROUP BY outcome;", [
        $user['fromuser'],
        Message::TYPE_OFFER
    ]);

    $nooutcome = 0;
    $withdrawn = 0;
    $taken = 0;

    foreach ($outcomes as $outcome) {
        if ($outcome['outcome'] == 'Taken') {
            $taken = $outcome['msgcount'];
        } else if ($outcome['outcome'] == 'Withdrawn') {
            $withdrawn = $outcome['msgcount'];
        } else {
            $nooutcome = $outcome['msgcount'];
        }
    }

    $outcomeProvided = 100 * ($taken + $withdrawn) / ($taken + $withdrawn + $nooutcome);

    #error_log("User " . $user['fromuser'] . " has $taken taken, $withdrawn withdrawn and $nooutcome no outcome");

    if ($outcomeProvided >= $threshold) {
        # This user is reliable about telling us what happened to their posts.
        # error_log("...neat user");
        $neatusers[] = $user['fromuser'];
    }
}

error_log("Found " . count($neatusers) . " neat users out of " . count($users));

$taken = [];
$withdrawn = [];

foreach ($neatusers as $neatuser) {
    # Get all the messages by this user.
    $msgs = $dbhr->preQuery("SELECT messages.id, outcome FROM messages 
        LEFT JOIN messages_outcomes ON messages.id = messages_outcomes.msgid 
        WHERE fromuser = ? AND type = ? AND arrival >= ?;", [
        $neatuser,
        Message::TYPE_OFFER,
        $mysqltime
    ]);

    foreach ($msgs as $msg) {
        # Count the replies.
        $replies = $dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages 
            WHERE refmsgid = ? AND type = ?;", [
            $msg['id'],
            ChatMessage::TYPE_INTERESTED
        ]);

        $replycount = $replies[0]['count'];

        if (!array_key_exists($replycount, $taken)) {
            $taken[$replycount] = 0;
        }

        if (!array_key_exists($replycount, $withdrawn)) {
            $withdrawn[$replycount] = 0;
        }

        if ($msg['outcome'] == Message::OUTCOME_TAKEN) {
            $taken[$replycount]++;
        } else if ($msg['outcome'] == Message::OUTCOME_WITHDRAWN) {
            $withdrawn[$replycount]++;
        } else {
            error_log("Message " . $msg['id'] . " has unexpected outcome {$msg['outcome']}");
            exit(0);
        }
    }
}

# Sorty by key
ksort($taken);

error_log("Reply count, % taken, % withdrawn");

foreach ($taken as $count => $num) {
    $total = $num + $withdrawn[$count];

    if ($total > 20) {
        error_log("$count," . (100 * $num / $total) . "," . (100 * $withdrawn[$count] / $total));
    }
}
