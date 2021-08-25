<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$locs = $dbhr->query("SELECT locations.id, locations.name FROM locations;");

$count = 0;
$bad = 0;

foreach ($locs as $loc) {
  try {
      $dbhr->preQuery("SELECT 'Shap CA10' REGEXP CONCAT('\\b', ?, '\\b');", [
          $loc['name']
      ]);
  } catch (\Exception $e) {
      error_log("Exception for {$loc['id']}, {$loc['name']}");
      $bad++;
      $dbhm->preExec("DELETE FROM locations WHERE id = ?;", [
          $loc['id']
      ]);
  }

  $count++;

  if ($count % 10000 == 0) {
      error_log("...$count");
  }
}

error_log("Found bad $bad");