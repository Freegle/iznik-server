<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));


do {
    $queue = $dbhr->preQuery("SELECT id FROM booktastic_ocr WHERE processed = 0;");

    foreach ($queue as $q) {
        $id = $q['id'];
        $c = new Catalogue($dbhr, $dbhm);
        $c->process($id);
        error_log("Completed $id");
    }

    sleep(1);
} while (true);
