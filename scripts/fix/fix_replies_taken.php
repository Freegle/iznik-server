<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$takens = [];
$withdrawn = [];
$expired = [];

$mysqltime = date ("Y-m-d", strtotime("Midnight 1 year ago"));
$msgs = $dbhr->preQuery("SELECT count(*) as count, refmsgid, outcome FROM chat_messages INNER JOIN messages ON chat_messages.refmsgid = messages.id LEFT JOIN messages_outcomes ON messages_outcomes.msgid = chat_messages.refmsgid WHERE chat_messages.date >= ? AND refmsgid IS NOT NULL AND chat_messages.type = 'Interested' AND messages.type = ? GROUP BY refmsgid ORDER BY count ASC;", [
    $mysqltime,
    Message::TYPE_OFFER
]);

foreach ($msgs as $msg) {
    if (!array_key_exists($msg['count'], $takens)) {
        $takens[$msg['count']] = 0;
    }

    if (!array_key_exists($msg['count'], $withdrawn)) {
        $withdrawn[$msg['count']] = 0;
    }

    if (!array_key_exists($msg['count'], $expired)) {
        $expired[$msg['count']] = 0;
    }

    if ($msg['outcome'] == 'Taken') {
        $takens[$msg['count']]++;
    } else if ($msg['outcome'] == 'Withdrawn') {
        $withdrawn[$msg['count']]++;
    } else {
        $expired[$msg['count']]++;
    }
}

foreach ($takens as $count => $num) {
    $total = $num + $withdrawn[$count] + $expired[$count];
    $known = $num + $withdrawn[$count];

    if ($total && $known) {
        error_log("$count," . (100 * $num / $known) . "," . (100 * $withdrawn[$count] / $total) . "," . (100 * $expired[$count] / $total));
    }
}


