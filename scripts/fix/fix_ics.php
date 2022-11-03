<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$t = new Tryst($dbhr, $dbhm);
$u = new User($dbhr, $dbhm);
$uid1 = $u->findByEmail('zzz');
$uid2 = $u->findByEmail('zzz');

$t->create($uid1, $uid2, Utils::ISODate('2023-01-01 00:00:00'));
$t->sendCalendar($uid1, 'zzz');