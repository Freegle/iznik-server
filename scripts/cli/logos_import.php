<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:');

if (($handle = fopen($opts['f'], "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $id = $data[0];
        $date = $data[2];
        $active = $data[4];

        $dbhm->preExec("UPDATE logos SET date = ?, active = ? WHERE id = ?", [
            $date,
            $active,
            $id
        ]);
    }

    fclose($handle);
}
