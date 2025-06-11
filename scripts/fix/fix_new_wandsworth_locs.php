<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

$loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
$twig = new \Twig_Environment($loader);

# Find the users who have received things.
$users = $dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = 126719 AND added >= ? AND added < ?;", [
    '2024-09-01',
    '2025-01-15',
]);

$latlngs = [];

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    list ($lat, $lng, $loc) = $u->getLatLng(FALSE, FALSE);

    if ($lat || $lng) {
        $key = "$lat,$lng";

        if (!array_key_exists($key, $latlngs)) {
            $latlngs[$key] = [
                'lat' => $lat,
                'lng' => $lng,
                'val' => 0,
            ];
        }

        $latlngs[$key]['val']++;
    }
}

echo "Longitude,Latitude,Value\n";

foreach ($latlngs as $key => $latlng) {
    echo $latlng['lng'] . ', ' . $latlng['lat'] . ", {$latlng['val']}\n";
}
