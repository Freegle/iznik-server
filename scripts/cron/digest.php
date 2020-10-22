<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

date_default_timezone_set('Europe/London');

$opts = getopt('i:m:v:g:');

if (count($opts) < 1) {
    echo "Usage: php digest.php -i <interval> (-m mod -v val) (-g groupid)\n";
} else {
    $interval = $opts['i'];
    $mod = Utils::presdef('m', $opts, 1);
    $val = Utils::presdef('v', $opts, 0);
    $gid = Utils::presdef('g', $opts, 0);

    if (!$gid) {
        # Specific groups are invoked manually.
        $lockh = Utils::lockScript(basename(__FILE__) . "-$interval-m$mod-v$val");
    }

    error_log("Start digest for $interval groupid % $mod = $val at " . date("Y-m-d H:i:s") . ($gid ? " group $gid" : ''));
    $start = time();
    $total = 0;

    # We only send digests for Freegle groups.
    $groupq = $gid ? (" AND id = " . intval($gid)) : '';
    $groups = $dbhr->preQuery("SELECT id, nameshort, settings FROM groups WHERE `type` = 'Freegle' AND onhere = 1 AND MOD(id, ?) = ? AND publish = 1 $groupq ORDER BY LOWER(nameshort) ASC;", [$mod, $val]);
    $d = new Digest($dbhr, $dbhm);

    foreach ($groups as $group) {
        $g = Group::get($dbhr, $dbhm, $group['id']);

        # Don't send for closed groups.
        if (!$g->getSetting('closed',FALSE)) {
            $total += $d->send($group['id'], $interval);
            if (file_exists('/tmp/iznik.mail.abort')) {
                break;
            }

            # Check for queue size on our mail host
            # TODO Make generic.
            $queuesize = trim(shell_exec("ssh -oStrictHostKeyChecking=no root@bulk2 \"/usr/sbin/postqueue -p | /usr/bin/tail -n1 | /usr/bin/gawk '{print $5}'\" 2>&1"));

            if (strpos($queuesize, "Total") !== FALSE) {
                $size = substr($queuesize, 6);

                if (intval($size) > 100000) {
                    error_log("bulk2 queue size large, sleep");
                    sleep(30);
                }
            }
        }
    }

    $duration = time() - $start;

    error_log("Finish digest for $interval at " . date("Y-m-d H:i:s") . ", sent $total mails in $duration seconds");

    if (!$gid) {
        Utils::unlockScript($lockh);
    }
}