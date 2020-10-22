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

$n = new Nearby($dbhr, $dbhm);
$count = 0;

$n->updateLocations();

$groups = $dbhr->preQuery("SELECT groups.id, groups.nameshort FROM groups WHERE groups.type = 'Freegle' AND publish = 1 AND onhere = 1 ORDER BY LOWER(nameshort) ASC;");
foreach ($groups as $group) {
    error_log($group['nameshort']);
    $g = Group::get($dbhr, $dbhm, $group['id']);

    if (!$g->getSetting('closed', FALSE)) {
        $thiscount = $n->messages($group['id']);
        error_log("...$thiscount");
        $count += $thiscount;
    }
}

error_log("Sent $count");

Utils::unlockScript($lockh);