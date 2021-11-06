<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

while (true) {
    $dbhm->preExec("delete from chat_messages where date >= '2021-11-05' and message like '%Around several months ago I have obtained access to your devices that you were using to browse internet%';");
    sleep(1);
}
