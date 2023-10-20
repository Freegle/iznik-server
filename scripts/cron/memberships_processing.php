<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$memberships = $dbhr->preQuery("SELECT id FROM `memberships_history` WHERE processingrequired = 1 ORDER BY id ASC;");
$u = new User($dbhr, $dbhm);

foreach ($memberships as $membership) {
    $u->processMembership($membership['id']);
}