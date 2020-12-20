<?php

namespace Freegle\Iznik;

use PhpMimeMailParser\Exception;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

$id = $opts['i'];

$a = new Authority($dbhr, $dbhm, $id);

//# Check for weird polygons.
//$groups = $dbhr->preQuery("SELECT id, nameshort FROM groups WHERE type = ? AND publish = 1 AND onmap = 1;", [
//    Group::GROUP_FREEGLE
//]);
//
//foreach ($groups as $group) {
//    try {
//        $sql = "SELECT groups.id, nameshort, namefull, lat, lng,
//       CASE WHEN poly IS NOT NULL THEN poly ELSE polyofficial END AS poly,
//       CASE
//         WHEN polyindex =
//              Coalesce(simplified, polygon) THEN 1
//         ELSE St_area(St_intersection(polyindex,
//                                     Coalesce(simplified, polygon)))
//              / St_area(polyindex)
//       end                          AS overlap,
//       CASE
//         WHEN polyindex =
//              Coalesce(simplified, polygon) THEN 1
//         ELSE St_area(polyindex) / St_area(
//                     St_intersection(polyindex,
//                             Coalesce(simplified, polygon)))
//       end                          AS overlap2
//FROM   groups
//       INNER JOIN authorities
//               ON ( polyindex =
//                    Coalesce(simplified, polygon)
//                     OR St_intersects(polyindex,
//                            Coalesce(simplified, polygon)) )
//WHERE  type = ?
//       AND publish = 1
//       AND onmap = 1
//       AND authorities.id = ?
//       AND groups.id = ?;";
//        $dbhr->preQuery($sql, [
//            Group::GROUP_FREEGLE,
//            $id,
//            $group['id']
//        ]);
//    } catch (\Exception $e) {
//        error_log("Exception on #{$group['id']} {$group['nameshort']}");
//    }
//}
//
$atts = $a->getPublic();

foreach ($atts['groups'] as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);

    if ($group['overlap'] > 0.5) {
        error_log("Moderate {$group['id']} " . $g->getName() . " overlap " . $group['overlap']);
        $settings = json_decode($g->getPrivate('settings'), TRUE);

        $dbhm->preExec("UPDATE groups SET precovidmoderated = ?, groups.overridemoderation = 'None', autofunctionoverride = 1  WHERE id = ?", [
            $g->getPrivate('autofunctionoverride') ? $g->getPrivate('precovidmoderated') : $settings['moderated'],
            $group['id']
        ]);

        $settings['moderated'] = 1;
        $g->setPrivate('settings', json_encode($settings));
    }
}
