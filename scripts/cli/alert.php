<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('g:s:b:');

if (count($opts) < 2) {
    echo "Usage: php alert.php -g <CSL id of group> -s <subject> -b <body>\n";
} else {
    $gids = $opts['g'];

    foreach (explode(',', $gids) as $gid) {
        $g = Group::get($dbhr, $dbhm, $gid);
        $subj = $opts['s'];
        $body = $opts['b'];

        if ($g->getId() == $gid && $subj && $body) {
            error_log($g->getName());
            $a = new Admin($dbhr, $dbhm);
            $a->create($gid, NULL, $subj, $body);
        } else {
            error_log("Couldn't find group $gid.");
        }
    }
}
