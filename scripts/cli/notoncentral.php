<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# This is for updates to the PAF file.  Don't run it for the initial load - it's too slow.

$g = new Group($dbhr, $dbhm);
$centralid = $g->findByShortName('FreegleUK-Central');

error_log("Central id is $centralid");

$groups = $dbhr->preQuery("SELECT groups.* FROM `groups` WHERE type = ? AND publish = 1 AND nameshort NOT LIKE '%playground%' ORDER BY LOWER(nameshort) ASC;", [
    Group::GROUP_FREEGLE
]);

$missing = [];

foreach ($groups as $group) {
    $oncentral = FALSE;
    $mods = $dbhr->preQuery("SELECT * FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');", [
        $group['id']
    ]);

    foreach ($mods as $mod) {
        $central = $dbhr->preQuery("SELECT * FROM memberships WHERE groupid = ? AND userid = ?;", [
            $centralid,
            $mod['userid']
        ]);

        foreach ($central as $c) {
            $u = new User($dbhr, $dbhm, $c['userid']);

            if ($u->getEmailPreferred() != MODERATOR_EMAIL) {
                error_log("...{$group['nameshort']} has mod #{$mod['userid']} " . $u->getEmailPreferred());
                $oncentral = TRUE;
                break 2;
            }
        }
    }

    if (!$oncentral) {
        error_log("...{$group['nameshort']} missing");
        $missing[] = $group;
    }
}

error_log("\n\nMissing central " . count($missing) . " of " . count($groups));

foreach ($missing as $m) {
    error_log($m['nameshort']);
}