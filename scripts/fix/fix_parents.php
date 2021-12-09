<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$l = new Location($dbhm, $dbhm);

error_log("Search");
$locs = $dbhm->preQuery("SELECT id FROM locations WHERE type = 'Postcode' AND LOCATE(' ', name) > 0 ORDER BY name ASC ;");
error_log("Searched " . count($locs));
$total = count($locs);
$changed = 0;

$count = 0;

foreach ($locs as $loc) {
    try {
//        $dbhm->preExec("UPDATE locations SET areaid = NULL WHERE id = ?", [
//            $loc['id']
//        ]);

        list ($changed, $areaid) = $l->setParents($loc['id']);

        if ($changed) {
            error_log("Changed location {$loc['id']}");
            $changed++;
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
            error_log("$count / $total...changed $changed");

            # Prod garbage collection, as we've seen high memory usage by this.
            gc_collect_cycles();
        }
    } catch (\Exception $e) {}
}
