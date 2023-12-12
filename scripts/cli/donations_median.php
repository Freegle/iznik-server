<?php

# Send test digest to a specific user
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$donors = $dbhr->preQuery("SELECT GrossAmount FROM `users_donations` WHERE timestamp >= ?", [
    date('Y-m-d', strtotime("365 days ago"))
]);

$donations = [];

foreach ($donors as $donor) {
    $donations[] = intval($donor['GrossAmount']);
}

sort($donations);
error_log("Median Donation is ". Utils::calculate_median($donations) . " from " . count($donations) . " donations");