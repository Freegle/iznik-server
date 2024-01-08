<?php
# php user_sleuth.php -f "First Last" -s Last -p 50 -g 30 -d 2023-11-13
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:s:d:p:g:');

$fn = trim($opts['f']);
$sn = trim($opts['s']);
$date = trim($opts['d']);
$maxPoss = intval($opts['p']) ?: 5;
$grace = intval($opts['g']) ?: 7;

function addPoss($poss, $users, $maxPoss = 5)
{
    global $dbhr, $dbhm;

    if (count($users) && count($users) < $maxPoss) {
        foreach ($users as $user) {
            $u = User::get($dbhr, $dbhm, $user['id']);

            $poss[$user['id']] = $user['displayname'] . " (" . $u->getEmailPreferred() . ")";
        }
    }

    return $poss;
}

function showPoss($fn, $sn, $poss, $maxPoss) {
    $poss = array_unique($poss);

    if (count($poss) && count($poss) <= $maxPoss) {
        error_log("$fn $sn possibilities:");

        foreach ($poss as $userid => $name)
        {
            error_log("  #$userid $name");
        }
    } else if (count($poss) > $maxPoss) {
        error_log("Too many possibilities (" . count($poss) . ")");
    } else {
        error_log("No possibilities found");
    }
}

if (strlen($fn) && strlen($sn)) {
    $poss = [];

    $users = $dbhr->preQuery("SELECT id, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname FROM users WHERE lastname LIKE ? AND ABS(DATEDIFF(lastaccess, ?)) < ?", [
        $sn,
        $date,
        $grace
    ]);

    $poss = addPoss($poss, $users, $maxPoss);

    $users = $dbhr->preQuery("SELECT id, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname  FROM users WHERE fullname LIKE '%$sn' AND ABS(DATEDIFF(lastaccess, ?)) < ?", [
        $date,
        $grace
    ]);
    $poss = addPoss($poss, $users, $maxPoss);

    $users = $dbhr->preQuery("SELECT id, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname  FROM users WHERE fullname LIKE '$fn $sn' AND ABS(DATEDIFF(lastaccess, ?)) < ?", [
        $date,
        $grace
    ]);
    $poss = addPoss($poss, $users, $maxPoss);

    $users = $dbhr->preQuery("SELECT users.id, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname  FROM users INNER JOIN users_emails ON users_emails.userid = users.id WHERE email LIKE '$fn$sn%' AND ABS(DATEDIFF(lastaccess, ?)) < ?", [
        $date,
        $grace
    ]);
    $poss = addPoss($poss, $users, $maxPoss);

    showPoss($fn, $sn, $poss, $maxPoss);
}