<?php
namespace Freegle\Iznik;

function jobs() {
    # This proxies a request on to adview to avoid CORS issues.
    global $dbhr, $dbhm;

    $link = Utils::presdef('link', $_REQUEST, NULL);
    $lat = Utils::presfloat('lat', $_REQUEST, NULL);
    $lng = Utils::presfloat('lng', $_REQUEST, NULL);
    $category = Utils::presdef('category', $_REQUEST, NULL);

    $me = Session::whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [
                'ret' => 2,
                'status' => 'Invalid parameters'
            ];

            if (!$lat && !$lng && $me) {
                # Default to our own location.
                list ($lat, $lng, $loc) = $me->getLatLng();
            }

            if ($lat || $lng) {
                $j = new Jobs($dbhr, $dbhm);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'jobs' => $j->query($lat, $lng, 50, $category)
                ];
            }
            break;
        }

        case 'POST': {
            $dbhm->preExec("INSERT INTO logs_jobs (userid, link) VALUES (?, ?);", [
                $me ? $me->getId() : NULL,
                $link
            ]);

            $ret = [ 'ret' => 0, 'status' => 'Success' ];
            break;
        }
    }

    return($ret);
}
