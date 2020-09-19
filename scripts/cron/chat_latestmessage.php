<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# This is a fallback, so it's only run occasionally through cron.

$mysqltime = date("Y-m-d", strtotime("31 days ago"));
$chatids = $dbhr->preQuery("SELECT DISTINCT chatid FROM chat_messages WHERE date >= ?;", [ $mysqltime ]);

$total = count($chatids);
$count = 0;

foreach ($chatids as $chatid) {
    $r = new ChatRoom($dbhr, $dbhm, $chatid['chatid']);
    $r->updateMessageCounts();

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}