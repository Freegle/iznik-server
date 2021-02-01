<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$ratings = $dbhr->preQuery("SELECT COUNT(*) AS count, ratee, timestamp FROM `ratings` GROUP BY timestamp HAVING count > 1;");
$count = 0;

foreach ($ratings as $r) {
    $others = $dbhr->preQuery("SELECT * FROM ratings WHERE ratee = ? AND timestamp = ?;", [
        $r['ratee'],
        $r['timestamp']
    ]);

    foreach ($others as $other) {
        $cr = new ChatRoom($dbhr, $dbhm);
        $rid = $cr->createConversation($other['rater'], $r['ratee']);
        $maxes = $dbhr->preQuery("SELECT MAX(date) AS m FROM chat_messages WHERE chatid = ?;", [
            $rid
        ]);

        foreach ($maxes as $max) {
            #error_log("...{$r['ratee']} <-> {$other['rater']} chat $rid {$r['timestamp']} => {$max['m']}");
            $dbhm->preExec("UPDATE ratings SET timestamp = ? WHERE id = ?;", [
                $max['m'],
                $other['id']
            ]);
        }
    }

    $count++;

    if ($count % 100 == 0) {
        error_log("$count / " . count($ratings));
    }
}
