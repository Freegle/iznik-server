<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$dbhback = new LoggedPDO('localhost:3309', $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE);

$opts = getopt('i:r');

if (count($opts) < 1) {
    echo "Usage: php volunteering_restore.php -i <id to restore> [-r]\n";
    echo "  -r  Restore all deleted Reach opportunities (externalid LIKE 'reach-%')\n";
} else {
    if (isset($opts['r'])) {
        error_log("Find all deleted Reach ops");
        $vols = $dbhback->preQuery("SELECT * FROM volunteering WHERE deleted = 1 AND externalid LIKE 'reach-%';");
        error_log("Found " . count($vols) . " deleted Reach opportunities to restore");
    } else {
        $id = $opts['i'];

        error_log("Find op $id");
        $vols = $dbhback->preQuery("SELECT * FROM volunteering WHERE id = ?;", [
            $id
        ]);
    }

    foreach ($vols as $vol) {
        error_log("Found it");
        $v = new Volunteering($dbhr, $dbhm);
        $id = $v->create($vol['userid'], $vol['title'], $vol['online'], $vol['location'], $vol['contactname'], $vol['contactphone'], $vol['contactemail'], $vol['contacturl'], $vol['description'], $vol['timecommitment']);

        # Preserve the pending status so approved opportunities don't show up for approval again
        $v->setPrivate('pending', $vol['pending']);

        # Preserve the externalid (important for Reach opportunities)
        if ($vol['externalid']) {
            $v->setPrivate('externalid', $vol['externalid']);
        }

        error_log("...restored as $id");

        $dates = $dbhback->preQuery("SELECT * FROM volunteering_dates WHERE volunteeringid = ?;", [ $vol['id'] ]);

        foreach ($dates as $date) {
            error_log("...restore date {$date['start']} - {$date['end']}, {$date['applyby']}");
            $v->addDate($date['start'], $date['end'], $date['applyby']);
        }

        $groups = $dbhback->preQuery("SELECT * FROM volunteering_groups WHERE volunteeringid = ?;", [ $vol['id'] ]);

        foreach ($groups as $group) {
            error_log("...restore group {$group['groupid']}");
            $v->addGroup($group['groupid']);
        }
    }
}
