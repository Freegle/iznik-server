<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$l = new Location($dbhm, $dbhm);

error_log("Search");
$locs = $dbhm->query("SELECT id FROM locations WHERE type = 'Postcode' AND LOCATE(' ', name) > 0 ORDER BY name ASC;");
error_log("Searched " . count($locs));
$total = count($locs);

$count = 0;

foreach ($locs as $loc) {
    try {
//        $dbhm->preExec("UPDATE locations SET areaid = NULL WHERE id = ?", [
//            $loc['id']
//        ]);

        if ($l->setParents($loc['id'])) {
            $msgs = $dbhr->preQuery("SELECT messages.id, messages_groups.groupid FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE locationid = ?;", [
                $loc['id']
            ]);

            foreach ($msgs as $msg) {
                $m = new Message($dbhr, $dbhm, $msg['id']);
                $oldsubj = $m->getSubject();
                $m->constructSubject($msg['groupid']);
                $newsubj = $m->getSubject();

                if ($oldsubj != $newsubj) {
                    error_log($msg['id'] . " $oldsubj => $newsubj");
                }
            }
        }

        $count++;

        if ($count % 1000 == 0) {
            error_log("$count / $total...");
        }
    } catch (\Exception $e) {}
}
