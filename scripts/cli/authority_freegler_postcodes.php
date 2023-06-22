<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php authority_freegler_postcode -i <authority ID>\n";
} else {
    $id = $opts['i'];

    $postcodes = [];

    $a = new Authority($dbhr, $dbhm, $id);
    $atts = $a->getPublic();
    $groups = $atts['groups'];
    $gids = array_column($groups, 'id');

    $uids = $dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid IN (" . implode(',', $gids) . ");");

    foreach ($uids as $uid) {
        $u = new User($dbhr, $dbhm, $uid['userid']);
        list ($lat, $lng, $loc) = $u->getLatLng(FALSE, FALSE);

        if ($lat || $lng) {
            $contains = $dbhr->preQuery("SELECT id FROM authorities WHERE id = ? AND ST_Contains(polygon, ST_GeomFromText('POINT($lng $lat)', {$dbhr->SRID()}));", [
                $id
            ]);

            if (count($contains) > 0) {
                $l = new Location($dbhr, $dbhm);
                $pc = $l->closestPostcode($lat, $lng);

                if ($pc) {
                    if (!array_key_exists($pc['name'], $postcodes)) {
                        $postcodes[$pc['name']] = 1;
                    } else {
                        $postcodes[$pc['name']]++;
                    }
                }
            }
        }
    }

    ksort($postcodes);

    echo "Postcode,Freegler Count\n";
    foreach ($postcodes as $pcname => $count) {
        echo "$pcname, $count\n";
    }
}