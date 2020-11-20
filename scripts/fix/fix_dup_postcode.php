<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$dups = $dbhr->preQuery("select name, count(*) as count from locations where type = 'Postcode' group by name having count > 1");

foreach ($dups as $dup) {
  $l = new Location($dbhr, $dbhm);
  $lid = $l->findByName($dup['name']);
  error_log("Delete $lid for {$dup['name']}");
  $l = new Location($dbhr, $dbhm, $lid);
  $l->delete();
}

error_log(count($dups));