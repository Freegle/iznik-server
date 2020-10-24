<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$groups = $dbhr->preQuery("SELECT * FROM groups WHERE type = 'Freegle' AND publish = 1 AND onmap = 1;");

foreach ($groups as $group) {
    try {
        $over = $dbhr->preQuery("SELECT ST_Intersection(GeomFromText(polyofficial), Coalesce(simplified, polygon)) FROM groups inner join `authorities` ON type = 'Freegle' AND publish = 1 AND onmap = 1 where authorities.id = 74579 and groups.id = ?", [ $group['id'] ]);
        #error_log("{$group['id']} {$group['nameshort']} CGA worked");

        if ($group['poly']) {
            $over = $dbhr->preQuery("SELECT ST_Intersection(GeomFromText(poly), Coalesce(simplified, polygon)) FROM groups inner join `authorities` ON type = 'Freegle' AND publish = 1 AND onmap = 1 where authorities.id = 74579 and groups.id = ?", [ $group['id'] ]);
            #error_log("{$group['id']} {$group['nameshort']} DPA worked");
        }
    } catch (\Throwable $e) {
        mail(GEEKS_ADDR, "Invalid CGA/DPA for {$group['id']} {$group['nameshort']}", $e->getMessage());
    }
}