<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/misc/Donations.php');
global $dbhr, $dbhm;

$target = $dbhr->preQuery("SELECT value FROM config WHERE `key` = 'ads_off_target_max';")[0]['value'];

// Use DONATIONS_EXCLUDE to filter out excluded payers (e.g., PayPal Giving Fund, Tipalti)
$excludeCondition = Donations::getExcludedPayersCondition('Payer');
$total = $dbhr->preQuery("SELECT SUM(GrossAmount) AS total FROM `users_donations` WHERE TIMESTAMPDIFF(HOUR, users_donations.timestamp, NOW()) <= 24 AND $excludeCondition ORDER BY timestamp DESC;")[0]['total'];

$toraise = ceil($target - $total);

if ($toraise < 0) {
    $toraise = 0;
}

$dbhm->preExec("UPDATE config SET value = ? WHERE `key` = 'ads_off_target';", [ $toraise ]);
$dbhm->preExec("UPDATE config SET value = ? WHERE `key` = 'ads_enabled';", [ $toraise > 0 ? 1 : 0 ]);