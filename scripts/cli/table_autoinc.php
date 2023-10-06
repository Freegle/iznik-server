<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$autoinc = time();

$tables = $dbhr->preQuery("SHOW TABLES");
foreach ($tables as $table)
{
    foreach ($table as $field => $tablename) {
        try
        {
            // This will throw an exception if the table doesn't have auto increment.
            $dbhm->preExec("ALTER TABLE $tablename AUTO_INCREMENT = " . $autoinc . ";");
            error_log("$tablename => $autoinc");

            $autoinc += 10000;
        } catch (\Exception $e){
        }
    }
}