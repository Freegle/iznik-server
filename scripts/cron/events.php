<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('m:v:');

if (count($opts) < 1) {
    echo "Usage: php event.php (-m mod -v val)\n";
} else {
    $mod = Utils::presdef('m', $opts, 1);
    $val = Utils::presdef('v', $opts, 0);

    $lockh = Utils::lockScript(basename(__FILE__) . "-m$mod-v$val");

    error_log("Start events for groupid % $mod = $val at " . date("Y-m-d H:i:s"));
    $start = time();
    $total = 0;

    $e = new EventDigest($dbhr, $dbhm, FALSE);

    # We only send events for Freegle groups.
    #
    # Cron should run this every week, but restrict to not sending them more than every few days to allow us to tweak the time.
    $sql = "SELECT id, nameshort FROM groups WHERE `type` = 'Freegle' AND onhere = 1 AND MOD(id, ?) = ? AND publish = 1 AND (lasteventsroundup IS NULL OR DATEDIFF(NOW(), lasteventsroundup) >= 3) ORDER BY LOWER(nameshort) ASC;";
    $groups = $dbhr->preQuery($sql, [$mod, $val]);

    foreach ($groups as $group) {
        error_log($group['nameshort']);
        $g = Group::get($dbhr, $dbhm, $group['id']);

        # Don't send to closed groups.
        if (!$g->getSetting('closed') && $g->getSetting('communityevents')) {
            $total += $e->send($group['id']);
        }
    }

    $duration = time() - $start;

    error_log("Finish events at " . date("Y-m-d H:i:s") . ", sent $total mails in $duration seconds");

    Utils::unlockScript($lockh);
}