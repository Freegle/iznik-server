<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Stream;
use Prewk\XmlStringStreamer\Parser;

$clusters = $dbhr->preQuery("select count(*) as count, city, ST_AsText(geometry) AS geom from jobs group by geometry order by count desc limit 50;");

foreach ($clusters as $cluster) {
    $g = new \geoPHP();
    $poly = $g::load($cluster['geom'], 'wkt');
    $bbox = $poly->getBBox();
    $swlat = $bbox['miny'];
    $swlng = $bbox['minx'];
    $nelat = $bbox['maxy'];
    $nelng = $bbox['maxx'];

    $jobs = $dbhr->preQuery("SELECT id FROM jobs WHERE city = ?;", [
        $cluster['city']
    ]);

    error_log("{$cluster['city']} " . count($jobs));

    foreach ($jobs as $job) {
        $newlat = $swlat + (mt_rand() / mt_getrandmax()) * ($nelat - $swlat);
        $newlng = $swlng + (mt_rand() / mt_getrandmax()) * ($nelng - $swlng);
        $swlng = $newlng - 0.0005;
        $swlat = $newlat - 0.0005;
        $nelat = $newlat + 0.0005;
        $nelng = $newlng + 0.0005;
        $geom = Utils::getBoxPoly($swlat, $swlng, $nelat, $nelng);
        $dbhm->preExec("UPDATE jobs SET geometry = ST_GeomFromText('$geom', {$dbhr->SRID()}) WHERE id = ?;", [
            $job['id']
        ]);
    }
}
