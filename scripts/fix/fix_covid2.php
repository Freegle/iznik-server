<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$users = $dbhr->preQuery("SELECT userid FROM covid WHERE dispatched IS NOT NULL");

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    $latlng = $u->getLatLng(FALSE, TRUE);
    if (!$latlng[0] && !$latlng[1]) {
        error_log("{$user['userid']} => " . json_encode($latlng));
        $dbhm->preExec("UPDATE covid SET dispatched = NULL, viewedown = NULL WHERE userid = ?", [
            $user['userid']
        ]);
        $dbhm->preExec("DELETE FROM covid_matches WHERE helpee = ?;", [
            $user['userid']
        ]);
    }
}
