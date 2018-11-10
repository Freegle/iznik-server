<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$emails = $dbhr->preQuery("SELECT memberships_yahoo.id FROM `memberships_yahoo` inner join memberships on memberships.id = memberships_yahoo.membershipid inner join groups on groups.id = memberships.groupid and type = 'Freegle';");
$total = count($emails);

error_log("Found $total\n");
$count = 0;

foreach ($emails as $email) {
    $dbhm->preExec("DELETE FROM memberships_yahoo WHERE id = ?;", [
        $email['id']
    ], FALSE);

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}

