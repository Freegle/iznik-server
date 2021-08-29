<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery("SELECT fromuser, messages.arrival FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser IS NOT NULL ORDER BY fromuser;");

$lastfrom = NULL;
$lasttime = NULL;
$maxgap = 0;
$gap = NULL;

foreach ($msgs as $msg) {
    $thistime = strtotime($msg['arrival']);

    if ($msg['fromuser'] != $lastfrom) {
        $gap = NULL;
        $lasttime = NULL;
    } else {
        $thisgap = $thistime - $lasttime;
        $gap = $gap ? max($thisgap, $gap) : $thisgap;

        if ($gap > $maxgap) {
            $maxgap = $gap;
            error_log("Max gap => $maxgap for {$msg['fromuser']} " . round($maxgap / 3600 / 24) . " days");
        }

        if ($gap > 3 * 365 * 3600 * 24) {
            error_log("Returned after 3 years {$msg['fromuser']}");
        }
    }

    $lasttime = $thistime;
    $lastfrom = $msg['fromuser'];
}
