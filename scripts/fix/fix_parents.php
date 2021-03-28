<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$l = new Location($dbhm, $dbhm);

error_log("Search");
$locs = $dbhm->preQuery("select l1.* from locations l1 left join locations l2 on l1.areaid = l2.id where l1.areaid is not null and l2.id is null;");
error_log("Searched " . count($locs));;

$count = 0;

foreach ($locs as $loc) {
    try {
//        $dbhm->preExec("UPDATE locations SET areaid = NULL WHERE id = ?", [
//            $loc['id']
//        ]);

        $l->setParents($loc['id']);

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

        $count++;

        if ($count % 10 == 0) {
            error_log("$count...");
        }
    } catch (\Exception $e) {}
}
