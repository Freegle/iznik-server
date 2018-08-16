<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/mail/Bounce.php');

$to = getenv('RECIPIENT');
$msg = '';

while(!feof(STDIN))
{
    $msg .= fread(STDIN, 1024);
}

$b = new Bounce($dbhr, $dbhm);
$id = $b->save($to, $msg);
error_log("Saved bounce $id $to");
