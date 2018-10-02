<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

foreach (['Barton-recycling',
             'Bromley-Freegle',
'Caerphilly_Freegle',
'CAMDENSOUTH_FREEGLE',
'FreeglePlayground',
'fromefreegle',
'IslingtonEastFreegle',
'IslingtonNorthFreegle',
'IslingtonSouthFreegle',
'IslingtonWestFreegle',
'kentishtown_freegle',
'newburyfreegle',
'TowerHamletsRecycle',
'WESTMINSTERUKFREEGLE'] as $name) {
    $g = new Group($dbhr, $dbhm);
    $gid = $g->findByShortName($name);

    if ($gid) {
        error_log("...move $name");
        $g = new Group($dbhr, $dbhm, $gid);
        $g->moveToNative();
    } else {
        error_log("...can't find $name");
    }
}
