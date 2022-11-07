<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php group_list_tems -i <authority IDs in a CSL>\n";
} else {
    $start = date('Y-m-d', strtotime("365 days ago"));

    $msgs = $dbhr->preQuery("SELECT messages.id, messages.arrival, messages.lat, messages.lng, messages.type, messages.subject, items.name AS item, items.weight AS EstimatedWeight, l1.name FROM messages 
           INNER JOIN messages_groups ON messages_groups.msgid = messages.id
           INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id
           LEFT JOIN locations l1 ON messages.locationid = l1.id 
		   INNER JOIN messages_items ON messages_items.msgid = messages.id
		   INNER JOIN items ON messages_items.itemid = items.id
           WHERE 
            messages_groups.groupid IN ({$opts['i']}) AND 
            messages.arrival >= ? AND 
            messages.type IN ('Offer', 'Wanted') AND
			messages_outcomes.outcome IN ('Taken', 'Received')
           GROUP BY items.name, messages.fromuser, DATE(messages.arrival)
		 ORDER BY messages.arrival ASC
			", [
        $start
    ]);

    fputcsv(STDOUT, [ 'MsgId', 'Arrival', 'Type', 'Item', 'ApproxWeight', 'PartialPostcode', 'ApproxLat', 'ApproxLng']);

    $l = new Location($dbhr, $dbhm);
    foreach ($msgs as $msg) {
//        $pc = $l->closestPostcode($msg['lat'], $msg['lng']);
//
//        if ($pc) {
//            $pcname = $pc['name'];
//            $pcname = substr($pcname, 0, strlen($pcname) - 2);
            list ($lat, $lng) = Utils::blur($msg['lat'], $msg['lng'], Utils::BLUR_USER);

            fputcsv(STDOUT, [ $msg['id'], $msg['arrival'], $msg['type'], $msg['item'], $msg['EstimatedWeight'], $lat, $lng]);
//        }
    }
}