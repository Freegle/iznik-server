<?php
namespace Freegle\Inzik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$token = '5b3ce3597851110001cf624863de9aed08394c60b6c5de3dfc91e089';
$pairs = $dbhr->preQuery("SELECT * FROM visualise ORDER BY RAND() LIMIT 500;");

foreach ($pairs as $pair) {
    do {
        $directions = @file_get_contents("https://api.openrouteservice.org/v2/directions/driving-car?api_key=$token&start={$pair['fromlng']},{$pair['fromlat']}&end={$pair['tolng']},{$pair['tolat']}");

        if (!$directions) {
            sleep(10);
        }
    } while (!$directions);

    $d = json_decode($directions, TRUE);
    foreach ($d['features'] as $f) {
        $distance = $f['properties']['segments'][0]['distance'];
        $duration = $f['properties']['segments'][0]['duration'];
        error_log("{$pair['id']}, {$pair['distance']}, $distance, $duration, " . round(100* $distance / $pair['distance']) . "," . round(100* $duration / $distance) . "," . round(100* $duration / $pair['distance']));
        sleep(5);
    }
}
