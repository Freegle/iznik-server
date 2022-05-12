<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

function addPoss(&$poss, $users)
{
    global $dbhr, $dbhm;

    if (count($users) && count($users) < 5) {
        foreach ($users as $user) {
            $u = User::get($dbhr, $dbhm, $user['id']);

            $poss[$user['id']] = $user['displayname'] . " (" . $u->getEmailPreferred() . ")";
        }
    }
}

function showPoss($fn, $sn, $poss) {
    $poss = array_unique($poss);

    if (count($poss) && count($poss) <= 5) {
        error_log("$fn $sn possibilities:");

        foreach ($poss as $userid => $name) {
            error_log("  #$userid $name");
        }
    }
}

if (($handle = fopen("/tmp/sleuth.csv", "r")) !== FALSE)
{
    fgetcsv($handle);

    while (($data = fgetcsv($handle, 1000, ",")) !== false) {
        $fn = trim($data[4]);
        $sn = trim($data[5]);

        if (strlen($fn) && strlen($sn)) {
            $poss = [];

            $users = $dbhr->preQuery("SELECT id, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname FROM users WHERE lastname LIKE ?", [
                $sn
            ]);

            addPoss($poss, $users);

            $users = $dbhr->preQuery("SELECT id, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname  FROM users WHERE fullname LIKE '%$sn'");
            addPoss($poss, $users);

            $users = $dbhr->preQuery("SELECT id, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname  FROM users WHERE fullname LIKE '$fn $sn'");
            addPoss($poss, $users);

            $users = $dbhr->preQuery("SELECT users.id, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname  FROM users INNER JOIN users_emails ON users_emails.userid = users.id WHERE email LIKE '$fn$sn%'");
            addPoss($poss, $users);

            showPoss($fn, $sn, $poss);
        }
    }
}