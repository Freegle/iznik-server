<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

try {
    $locationchange = date("Y-m-d", strtotime("24 hours ago"));
    $earliestmessage = date("Y-m-d", strtotime("Midnight " . Message::EXPIRE_TIME . " days ago"));

    $messages = $dbhr->preQuery("SELECT messages.id, messages.subject, messages_groups.groupid, locations.name FROM locations INNER JOIN messages ON messages.locationid = locations.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE locations.timestamp >= ? AND messages.arrival >= '2021-12-21' AND messages.arrival >= ?;", [
        $locationchange,
        $earliestmessage
    ]);

    $changed = 0;

    foreach ($messages as $message) {
        $m = new Message($dbhr, $dbhm, $message['id']);

        $newsubj = $m->constructSubject($message['groupid'], FALSE);

        if (preg_match('/.*?\:(.*)\((.*)\)/', $message['subject'], $oldmatches) &&
            preg_match('/.*?\:(.*)\((.*)\)/', $newsubj, $newmatches)) {
            $oldloc = $oldmatches[2];
            $newloc = $newmatches[2];

            if (strcasecmp($oldloc, $newloc)) {
                error_log("Message #{$message['id']} in {$message['name']} $oldloc => $newloc gives => $newsubj");
                $m->setPrivate('subject', $newsubj);
                $changed++;
            }
        }

    }

    error_log("Changed $changed of " . count($messages));
} catch (\Exception $e) {
    \Sentry\captureException($e);
    error_log("Failed " . $e->getMessage());
};


Utils::unlockScript($lockh);