<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Get stats.
$msgs = $dbhr->preQuery("SELECT DISTINCT(msgid) FROM messages_attachments_recognise
INNER JOIN messages_attachments ON messages_attachments.id = attid
WHERE msgid IS NOT NULL
ORDER BY msgid DESC;");

error_log("Found " . count($msgs) . " messages to process");

$avg = $dbhr->preQuery("SELECT SUM(popularity * weight) / SUM(popularity) AS average FROM items WHERE weight IS NOT NULL AND weight != 0")[0]['average'];

$totalcurrent = 0;
$totalai = 0;
$agreeish = 0;
$disagree = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['msgid']);

    # Get our current weight estimate.
    $sql = "SELECT weight FROM messages_items LEFT JOIN items ON items.id = messages_items.itemid WHERE messages_items.msgid = ?;";

    $currents = $dbhr->preQuery($sql, [
        $msg['msgid']
    ]);

    $weight = 0;
    foreach ($currents as $current) {
        $weight += $current['weight'] ? $current['weight'] : $avg;
    }

    # Get the approximateWeightInKg from the row in messages_Attachements_recognise where the msgid is the same as the current msgid with the lowest id
    $recognise = $dbhr->preQuery("SELECT JSON_EXTRACT(info, '$.approximateWeightInKg') AS weight FROM messages_attachments 
               LEFT JOIN messages_attachments_recognise ON messages_attachments_recognise.attid = messages_attachments.id 
               WHERE msgid = ? ORDER BY messages_attachments.id ASC LIMIT 1;", [
        $msg['msgid']
    ]);

    $aiweight = $recognise[0]['weight'];

    if ($aiweight) {
        # Log any where one is more than 30% different from the other.
        $totalai += $aiweight;
        $totalcurrent += $weight;

        if (abs($weight - $aiweight) / $weight > 0.3) {
            error_log("{$msg['msgid']} {$m->getSubject()} current $weight vs AI $aiweight");
            $disagree++;
        } else {
            $agreeish++;
        }
    }
}

error_log("\n\nTotals current $totalcurrent vs AI $totalai");
error_log("Agreeish % " . ($agreeish / ($agreeish + $disagree) * 100));