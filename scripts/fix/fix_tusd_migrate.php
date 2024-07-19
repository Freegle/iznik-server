<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('t:i:y:');

if (count($opts) < 1) {
    echo "Usage: php fix_tusd_migrate.php -t <table> -y <attachment type> (-i <id>) \n";
    exit(1);
}

$table = $opts['t'];
$id = $opts['i'];
$type = $opts['y'];

error_log("Querying for entries");

if ($id) {
    $entries = $dbhr->preQuery("SELECT id, externaluid FROM `$table` WHERE id = ? AND externaluid IS NOT NULL AND externalmods IS NOT NULL AND externaluid NOT LIKE 'freegletusd-%';", [
        $id
    ]);
} else {
    $entries = $dbhr->preQuery("SELECT id, externaluid FROM `$table` WHERE externaluid IS NOT NULL AND externalmods IS NOT NULL AND externaluid NOT LIKE 'freegletusd-%' ORDER BY id DESC;");
}

error_log("Found " . count($entries) . " to migrate");
$count = 0;
$total = count($entries);

foreach ($entries as $entry) {
    $a = new Attachment($dbhr, $dbhm, $entry['id'], $type);
    error_log("...{$entry['id']}");
    $path = $a->getPath();
    $olduid = $a->getExternalUid();
    $newurl = Tus::upload($path);

    if ($newurl) {
        $newuid = "freegletusd-" . substr($newurl, strrpos($newurl, '/') + 1);

        $sql = "UPDATE `$table` SET externaluid = '$newuid' WHERE id = {$entry['id']};";
        error_log("Forward: $sql");
        error_log("Reverse: UPDATE `$table` SET externaluid = '$olduid' WHERE id = {$entry['id']};");
        $dbhm->preExec($sql);
    } else {
        exit;
    }

    $count++;

    if ($count % 100 == 0) {
        error_log("$count / $total");
    }
}


