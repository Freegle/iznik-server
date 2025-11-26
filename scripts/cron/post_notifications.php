<?php
/**
 * Cron script to send push notifications about new OFFER/WANTED posts.
 *
 * This script should be run frequently (e.g., every 5 minutes) and will
 * process all frequency types. Each frequency is only processed when
 * enough time has passed since the last notification.
 *
 * Usage: php post_notifications.php -i <interval> [-m mod -v val] [-g groupid]
 *
 * Parameters:
 *   -i interval: Digest frequency constant (-1=immediate, 1=hourly, 24=daily, etc.)
 *   -m mod: For sharding across multiple processes (groupid % mod = val)
 *   -v val: Shard value to process
 *   -g groupid: Process a specific group only (for testing)
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

date_default_timezone_set('Europe/London');

$opts = getopt('i:m:v:g:');

if (count($opts) < 1) {
    echo "Usage: php post_notifications.php -i <interval> (-m mod -v val) (-g groupid)\n";
    echo "  -i: Digest frequency (-1=immediate, 1=hourly, 2=every2hrs, 4=every4hrs, 8=every8hrs, 24=daily)\n";
    echo "  -m: Modulo for sharding (optional)\n";
    echo "  -v: Value for sharding (optional)\n";
    echo "  -g: Specific group ID (optional, for testing)\n";
    exit(1);
}

$interval = intval($opts['i']);
$mod = Utils::presdef('m', $opts, 1);
$val = Utils::presdef('v', $opts, 0);
$gid = Utils::presdef('g', $opts, 0);

if (!$gid) {
    $lockh = Utils::lockScript(basename(__FILE__) . "-$interval-m$mod-v$val");
}

error_log("Start post notifications for interval $interval groupid % $mod = $val at " . date("Y-m-d H:i:s") . ($gid ? " group $gid" : ''));
$start = time();
$total = 0;

// We only send notifications for Freegle groups
$groupq = $gid ? (" AND id = " . intval($gid)) : '';
$groups = $dbhr->preQuery(
    "SELECT id, nameshort FROM `groups`
     WHERE `type` = 'Freegle'
       AND onhere = 1
       AND MOD(id, ?) = ?
       AND publish = 1
       $groupq
     ORDER BY LOWER(nameshort) ASC;",
    [$mod, $val]
);

$n = new PostNotifications($dbhr, $dbhm);

foreach ($groups as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);

    // Don't send for closed groups
    if (!$g->getSetting('closed', FALSE)) {
        $sent = $n->send($group['id'], $interval);
        $total += $sent;

        if ($sent > 0) {
            error_log("  {$group['nameshort']}: sent $sent notifications");
        }

        Utils::checkAbortFile();
    }
}

$duration = time() - $start;

error_log("Finish post notifications for interval $interval at " . date("Y-m-d H:i:s") . ", sent $total notifications in $duration seconds");

if (!$gid) {
    Utils::unlockScript($lockh);
}
