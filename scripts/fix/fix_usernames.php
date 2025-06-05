<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT id FROM users WHERE fullname like 'Freegle';");

foreach ($users as $user) {
    error_log($user['id']);
    $u = new User($dbhr, $dbhm, $user['id']);
    $invented = $u->inventEmail(TRUE);
    $name = substr($invented, 0, strpos($invented, '-'));
    $u->setPrivate('fullname', $name);
    $u->setPrivate('inventedname', 1);
}