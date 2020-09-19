<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Volunteering.php');

$dsn = "mysql:host=localhost;port=3309;dbname=iznik;charset=utf8";
$dbhback = new LoggedPDO($dsn, SQLUSER, SQLPASSWORD, array(
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_EMULATE_PREPARES => FALSE
));

    $vols = $dbhback->preQuery("SELECT * FROM ratings;");

    foreach ($vols as $vol) {
        error_log("UPDATE ratings SET timestamp = '{$vol['timestamp']}' WHERE id = {$vol['id']};");
    }

