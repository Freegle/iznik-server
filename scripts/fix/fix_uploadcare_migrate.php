<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('t:i:');

if (count($opts) < 1) {
    echo "Usage: php fix_uploadcare_migrate.php -t <table> (-i <id>) \n";
    exit(1);
}

$table = $opts['t'];
$id = $opts['i'];

error_log("Querying for entries");

if ($id) {
    $entries = $dbhr->preQuery("SELECT id FROM `$table` WHERE id = ? AND data IS NOT NULL;", [
        $id
    ]);
} else {
    $entries = $dbhr->preQuery("SELECT id FROM `$table` WHERE data IS NOT NULL;");
}

error_log("Found " . count($entries) . " to migrate");
$count = 0;
$total = count($entries);

foreach ($entries as $entry) {
    $u = new UploadCare();
    $data = $dbhr->preQuery("SELECT data FROM `$table` WHERE id = ?;", [
        $entry['id']
    ])[0]['data'];

    $uid = $u->upload($data, 'image/jpeg');

    if (!$uid) {
        error_log("Failed to upload");
        exit(0);
    }

    $dbhm->preExec("UPDATE `$table` SET externaluid = ?, externalmods = NULL, data = NULL WHERE id = ?", [
        $uid,
        $entry['id']
    ]);

    $count++;

    if ($count % 100 == 0) {
        error_log("$count / $total");
    }
}


