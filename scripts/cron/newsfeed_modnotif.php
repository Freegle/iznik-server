<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

$lockh = Utils::lockScript(basename(__FILE__));

$donemods = [];

$n = new Newsfeed($dbhr, $dbhm);
$groups = $dbhr->preQuery("SELECT * FROM groups WHERE type = 'Freegle' AND onhere = 1 AND publish = 1 AND nameshort NOT LIKE '%playground%' ORDER BY RAND();");
foreach ($groups as $group) {
    $g = new Group($dbhr, $dbhm, $group['id']);
    $mods = $g->getMods();

    foreach ($mods as $mod) {
        if (!Utils::pres($mod, $donemods)) {
            $n->modnotif($mod);
            $donemods[$mod] = TRUE;
        }
    }
}

Utils::unlockScript($lockh);