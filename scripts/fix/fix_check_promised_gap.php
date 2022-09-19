<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$promises = $dbhr->preQuery("SELECT messages_promises.promisedat, messages_outcomes.timestamp 
    FROM messages_promises INNER JOIN messages_outcomes on messages_promises.msgid = messages_outcomes.msgid 
    WHERE promisedat >= '2021-09-10' AND outcome = 'Taken';");

error_log("Found " . count($promises));

$timestamps = [];

foreach ($promises as $promise) {
    $promisedat = strtotime($promise['promisedat']);
    $takenat = strtotime($promise['timestamp']);
    $diff = $takenat - $promisedat;
    $diff = round($diff / (60 * 60));

    $timestamps[] = $diff;
}

$median = Utils::calculate_median($timestamps);

error_log("Median $median");


