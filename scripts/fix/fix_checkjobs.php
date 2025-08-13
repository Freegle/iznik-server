<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

if (($handle = fopen("/tmp/feed.csv", "r")) !== FALSE)
{
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE)
    {
        error_log(var_export($data, TRUE));
        $valid = $dbhm->preQuery("SELECT ST_IsValid(ST_GeomFromText(?, {$dbhr->SRID()})) AS valid;", [
            $data[17]
        ]);
    }
}
