<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php locations_setparents.php -i id\n";
} else {
    $dbhr->errorLog = TRUE;
    $dbhm->errorLog = TRUE;
    $l = new Location($dbhm, $dbhm);
    list ($changed, $areadid) = $l->setParents($opts['i']);
    $dbhr->errorLog = FALSE;
    $dbhm->errorLog = FALSE;

    if ($areadid) {
        $l = new Location($dbhm, $dbhm, $areadid);
        error_log("Mapped to #$areadid " . $l->getPrivate('name'));
    }
}
