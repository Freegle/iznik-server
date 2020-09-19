<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('n:');

if (count($opts) > 1) {
    echo "Usage: php group_delete.php (-n <groupname>)\n";
} else {
    $groupname = Utils::presdef('n', $opts, NULL);
    $g = Group::get($dbhr, $dbhm);
    $gid = $groupname ? $g->findByShortName($groupname) : NULL;

    if (!$gid) {
        error_log("Failed to find $groupname");
        exit(1);
    }

    # Get referenced tables - we delete from them individually rather than rely on cascades to avoid DB hangs.
    $schemas = $dbhr->preQuery("SELECT   TABLE_NAME,   COLUMN_NAME,   CONSTRAINT_NAME,      REFERENCED_TABLE_NAME,   REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE   REFERENCED_TABLE_NAME = 'groups' AND TABLE_NAME != 'groups' AND table_schema = ?;", [
        SQLDB
    ], FALSE);

    foreach ($schemas as $schema) {
        $count = $dbhr->preQuery("SELECT COUNT(*) AS count FROM {$schema['TABLE_NAME']} WHERE {$schema['COLUMN_NAME']} = $gid");
        if ($count[0]['count']) {
            error_log("...{$schema['TABLE_NAME']} has {$count[0]['count']} rows");
        }
    }

    echo "Are you sure you want to delete $groupname (#$gid)?  Type '$groupname' to continue: ";
    $handle = fopen ("php://stdin","r");
    $line = fgets($handle);
    if(trim($line) != $groupname){
        echo "ABORTING!\n";
        exit;
    }
    fclose($handle);

    foreach ($schemas as $schema) {
        do {
            $counts = $dbhr->preQuery("SELECT COUNT(*) AS count FROM {$schema['TABLE_NAME']} WHERE {$schema['COLUMN_NAME']} = $gid", []);
            $count = $counts[0]['count'];

            if ($count) {
                $dbhm->preExec("DELETE FROM {$schema['TABLE_NAME']} WHERE {$schema['COLUMN_NAME']} = $gid LIMIT 100");
                error_log("{$schema['TABLE_NAME']}...$count");
            }
        } while ($count);
    }

    $g = Group::get($dbhr, $dbhm, $gid);
    $g->delete();
}
