<?php

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/newsfeed/Newsfeed.php');

$lockh = lockScript(basename(__FILE__));

$donemods = [];

$n = new Newsfeed($dbhr, $dbhm);
$groups = $dbhr->preQuery("SELECT * FROM groups WHERE type = 'Freegle' AND onhere = 1 AND publish = 1 AND nameshort NOT LIKE '%playground%' ORDER BY RAND();");
foreach ($groups as $group) {
    $g = new Group($dbhr, $dbhm, $group['id']);
    $mods = $g->getMods();

    foreach ($mods as $mod) {
        if (!pres($mod, $donemods)) {
            $n->modnotif($mod);
            $donemods[$mod] = TRUE;
        }
    }
}

unlockScript($lockh);