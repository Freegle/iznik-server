<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

$lockh = Utils::lockScript(basename(__FILE__));

session_start();

$rl = new Relevant($dbhr, $dbhm);
$count = 0;
$upto = 0;

$mysqltime = date("Y-m-d", strtotime("Midnight 90 days ago"));
$users = $dbhr->preQuery("SELECT id FROM users WHERE lastaccess >= ? AND lastlocation IS NOT NULL AND relevantallowed = 1;", [
    $mysqltime
]);
$total = count($users);
error_log("$total users");

foreach ($users as $user) {
    $count += $rl->sendMessages($user['id']);
    $rl->recordCheck($user['id']);

    $upto++;

    if ($upto % 1000 == 0) {
        error_log("...$upto / $total");
    }
}

error_log("Sent $count");

Utils::unlockScript($lockh);