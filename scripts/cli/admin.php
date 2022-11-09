<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('g:s:b:l:t:');

if (count($opts) < 2) {
    echo "Usage: php admin.php -g <CSL id of group> -s <subject> -b <filename containing body> -l <CTA link> -t <CTA text>\n";
} else {
    $gids = $opts['g'];
    error_log("Groups $gids");

    foreach (explode(',', $gids) as $gid) {
        $g = Group::get($dbhr, $dbhm, $gid);
        $subj = $opts['s'];
        $body = file_get_contents($opts['b']);
        $ctalink = $opts['l'];
        $ctatext = $opts['t'];

        if ($g->getId() == $gid && $subj && $body) {
            error_log("...process " . $g->getName());
            $a = new Admin($dbhr, $dbhm);
            $a->create($gid, NULL, $subj, $body, $ctatext, $ctalink);
        } else {
            error_log("Couldn't find group $gid.");
        }
    }
}
