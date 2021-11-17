<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$date = '2021-04-28';
error_log($date);

$groups = $dbhr->preQuery("SELECT * FROM `groups` WHERE type = 'Freegle' ORDER BY nameshort ASC;");
foreach ($groups as $group) {
    error_log("...{$group['nameshort']}");
    $s = new Stats($dbhr, $dbhm, $group['id']);
    $s->generate($date);
}
