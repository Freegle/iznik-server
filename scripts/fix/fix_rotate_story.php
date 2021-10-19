<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$a = new Attachment($dbhr, $dbhm, 1445, Attachment::TYPE_STORY);
$data = $a->getData();
error_log("Data len " . strlen($data));
$i = new Image($data);
$i->rotate(90);
$newdata = $i->getData(100);
$a->setData($newdata);

