<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:a:r:i:p:');

$ids = $dbhr->preQuery("select id from chat_messages where date < '2023-07-07' and processingsuccessful = 0;");
$total = count($ids);
$count = 0;

foreach ($ids as $id) {
    $dbhm->preExec("UPDATE chat_messages SET processingsuccessful = 1 WHERE id = ?;", [
        $id['id']
    ]);

    $count++;

    if ($count % 1000 == 0) {
        error_log("...updated $count / $total");
    }
}