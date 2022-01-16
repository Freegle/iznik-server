<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('n:');

if (count($opts) < 1) {
    echo "Usage: php facebook_share_popular -n <shortname of group>\n";
} else {
    $name = $opts['n'];
    $g = new Group($dbhr, $dbhm);
    $gid = $g->findByShortName($name);

    if ($gid) {
        $msgs = $g->getPopularMessages($gid);

        if ($msgs) {
            foreach ($msgs as $msg) {
                $msgid = $msg['msgid'];
                error_log("Share popular #$msgid");
                $f = new GroupFacebook($dbhr, $dbhm);
                $f->sharePopularMessage($gid, $msgid, TRUE);
            }
        } else {
            error_log("No popular posts today.");
        }
    } else {
        error_log("Group not found");
    }
}

