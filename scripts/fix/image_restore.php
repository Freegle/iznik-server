<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$dbhback = new LoggedPDO('localhost:3309', $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE);

$opts = getopt('t:');

$table = $opts['t'];
$atts = $dbhback->preQuery("SELECT * FROM $table WHERE externaluid IS NOT NULL;");

foreach ($atts as $att) {
    $dbhm->preExec("UPDATE $table SET externaluid = ? WHERE id = ?;", [
        $att['externaluid'],
        $att['id']
    ]);
}
