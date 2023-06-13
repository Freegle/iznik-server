<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$sql = "SELECT id, nameshort, CASE WHEN poly IS NULL THEN polyofficial ELSE poly END AS poly FROM `groups` WHERE type = 'Freegle' AND (poly IS NOT NULL OR polyofficial IS NOT NULL) AND publish = 1 ORDER BY nameshort ASc;";
$groups = $dbhr->preQuery($sql);

$kml = "<?xml version='1.0' encoding='UTF-8'?>
<kml xmlns='http://www.opengis.net/kml/2.2'>
    <Document>
        <name>Freegle Group Areas</name>
        <description><![CDATA[Boundaries for Freegle groups]]></description>
        <Folder>
            <name>Groups</name>";

foreach ($groups as $group) {
    error_log("Group {$group['nameshort']}");
    $geom = \geoPHP::load($group['poly'], 'wkt');
    $kml .= "<Placemark><name>{$group['nameshort']}</name>" . $geom->out('kml') . "</Placemark>\r\n";
}

$kml .= "		</Folder>
	</Document>
</kml>\r\n";

echo $kml;
