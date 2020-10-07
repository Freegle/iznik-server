<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery("SELECT messages.id, locations.lat, locations.lng FROM messages inner join locations on locations.id = messages.locationid where messages.lat is not null and messages.lng is not null and (messages.lat != locations.lat or messages.lng != locations.lng)");

$count = 0;
$total = count($msgs);

foreach ($msgs as $msg) {
    $dbhm->preExec("UPDATE messages SET lat = ?, lng = ? WHERE id = ?;", [
        $msg['lat'],
        $msg['lng'],
        $msg['id']
    ]);

    $count++;

    if ($count % 10000 == 0) {
        error_log("...$count / $total");
    }
}