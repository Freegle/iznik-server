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

$users = $dbhr->preQuery("SELECT id FROM users WHERE lastlocation IS NOT NULL AND relevantallowed = 1;");
foreach ($users as $user) {
    $count += $rl->sendMessages($user['id']);
    $rl->recordCheck($user['id']);
}

error_log("Sent $count");

Utils::unlockScript($lockh);