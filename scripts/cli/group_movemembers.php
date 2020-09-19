<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:t:');

if (count($opts) < 2) {
    echo "Usage: php group_movemembers.php -f <shortname of source group> -t <short name of destination group>\n";
} else {
    $from = $opts['f'];
    $to = $opts['t'];
    $g = Group::get($dbhr, $dbhm);

    $srcid = $g->findByShortName($from);
    $dstid = $g->findByShortName($to);
    $dstg = Group::get($dbhr, $dbhm, $dstid);

    $moved = 0;
    $alreadys = 0;

    if ($srcid && $dstid) {
        $membs = $dbhr->preQuery("SELECT * FROM memberships WHERE groupid = ?;", [ $srcid ]);
        foreach ($membs as $memb) {
            $membs2 = $dbhr->preQuery("SELECT * FROM memberships WHERE groupid = ? AND userid = ?;", [$dstid, $memb['userid']]);
            $already = count($membs2) > 0;
            if (!$already) {
                $dbhm->preQuery("UPDATE memberships SET groupid = ? WHERE groupid = ? AND userid = ?;", [$dstid, $srcid, $memb['userid']]);
                $moved++;
            } else {
                $alreadys++;
            }
        }
    } else {
        error_log("Groups not found");
    }

    error_log("Moved $moved, already member $alreadys");
    $dbhm->preExec("DELETE FROM memberships WHERE groupid = ?;", [ $srcid ]);
}
