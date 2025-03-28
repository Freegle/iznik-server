<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Get stats.
$msgs = $dbhr->preQuery("SELECT msgid, attid, rating FROM messages_attachments_recognise
INNER JOIN messages_attachments ON messages_attachments.id = attid
WHERE msgid IS NOT NULL AND messages_attachments_recognise.timestamp >= '2025-03-25'
ORDER BY msgid DESC;");

error_log("Found " . count($msgs) . " messages to process");

$passivegood = 0;
$passivebad = 0;
$passivebadsubject = 0;
$passivebadtextbody = 0;
$activegood = 0;
$activebad = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['msgid']);
    $recognise = $dbhr->preQuery("SELECT info FROM messages_attachments_recognise WHERE attid = ? ORDER BY id DESC LIMIT 1;", [
        $msg['attid']
    ]);

    $json = json_decode($recognise[0]['info'], true);
    $aisubject = $json['shortDescription'];

    // Remove trailing punctuation
    $aisubject = preg_replace('/[.,;:!?]+$/', '', $aisubject);
    $aitextbody = $json['longDescription'];

    $finalsubject = $m->getItems()[0]['name'];
    $finaltextbody = trim(str_replace('(AI text based on photo)', '', $m->getTextBody()));

    if ($msg['rating']) {
        if ($msg['rating'] == 'Good') {
            $activegood++;
        } else {
            $activebad++;
        }
    }

        if ($aisubject != $finalsubject) {
            error_log("https://www.ilovefreegle.org/message/" . $msg['msgid'] . " Subject mismatch: AI: $aisubject, final: $finalsubject");
            $passivebad++;
            $passivebadsubject++;
        } else if ($aitextbody != $finaltextbody) {
            #error_log("https://www.ilovefreegle.org/message/" . $msg['msgid'] . " Text body mismatch: AI: $aitextbody, final: $finaltextbody");
            $passivebad++;
            $passivebadtextbody++;
        } else {
            $passivegood++;
        }
}

if ($activegood + $activebad) {
    $activeapproval = round(100 * $activegood / ($activegood + $activebad));
} else {
    $activeapproval = 0;
}

if ($passivegood + $passivebad) {
    $passiveapproval = round(100 * $passivegood / ($passivegood + $passivebad));
} else {
    $passiveapproval = 0;
}

$passivebadsubject = round(100 * $passivebadsubject / $passivebad);
$passivebadtextbody = round(100 * $passivebadtextbody / $passivebad);

error_log("Active approval: $activeapproval%");
error_log("Passive approval: $passiveapproval%, subject changes $passivebadsubject% vs text body changes $passivebadtextbody%");