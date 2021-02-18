<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

$authorityid = $opts['i'];

$groups = $dbhr->preQuery("SELECT * FROM groups WHERE type = ? AND publish = 1 AND onmap = 1", [
  Group::GROUP_FREEGLE
]);

foreach ($groups as $group) {
  try {
      $sql = "SELECT groups.id, nameshort, namefull, lat, lng, 
             CASE WHEN poly IS NOT NULL THEN poly ELSE polyofficial END AS poly, 
             CASE 
               WHEN polyindex = 
                    Coalesce(simplified, polygon) THEN 1 
               ELSE St_area(St_intersection(polyindex, 
                                           Coalesce(simplified, polygon))) 
                    / St_area(polyindex) 
             end                          AS overlap, 
             CASE 
               WHEN polyindex = 
                    Coalesce(simplified, polygon) THEN 1 
               ELSE St_area(polyindex) / St_area( 
                           St_intersection(polyindex, 
                                   Coalesce(simplified, polygon))) 
             end                          AS overlap2 
      FROM   groups 
             INNER JOIN authorities 
                     ON ( polyindex = 
                          Coalesce(simplified, polygon) 
                           OR St_intersects(polyindex, 
                                  Coalesce(simplified, polygon)) ) 
      WHERE  type = ? 
             AND publish = 1 
             AND onmap = 1 
             AND authorities.id = ?
             AND groups.id = ? 
      ;";

      $groups = $dbhr->preQuery($sql, [
          Group::GROUP_FREEGLE,
          $authorityid,
          $group['id']
      ]);
  } catch (\Exception $e) {
      error_log("...problem with {$group['id']} {$group['nameshort']}");
  }
}
