<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');


$to = trim($argv[2]);

$msg = '';

while(!feof(STDIN))
{
    $msg .= fread(STDIN, 1024);
}

$b = new Bounce($dbhr, $dbhm);
$id = $b->save($to, $msg);
error_log("Saved bounce $id $to");
