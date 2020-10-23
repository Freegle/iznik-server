<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('g:c:');

if (count($opts) < 2) {
    echo "Usage: php group_closed -g <shortname of source group> -c <1 = closed, 0 = open>\n";
} else {
    $name = $opts['g'];
    $closed = $opts['c'];
    $g = Group::get($dbhr, $dbhm);

    $gid = $g->findByShortName($name);

    if (!$gid) {
        error_log("$name not found");
    } else {
        $g = Group::get($dbhr, $dbhm, $gid);
        $settings = json_decode($g->getPrivate('settings'), TRUE);
        $settings['closed'] = $closed;
        $g->setPrivate('settings', json_encode($settings));
    }
}
