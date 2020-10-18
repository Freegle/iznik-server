<?php
namespace Freegle\Iznik;

function adview() {
    # This proxies a request on to adview to avoid CORS issues.
    global $dbhr, $dbhm;

    $ip = Utils::presdef('REMOTE_ADDR', $_SERVER, NULL);
    $hdrs = Session::getallheaders();
    $ip = Utils::presdef('X-Real-Ip', $hdrs, $ip);

    $location = Utils::presdef('location', $_REQUEST, NULL);
    $link = Utils::presdef('link', $_REQUEST, NULL);
    $me = Session::whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $data = NULL;
            $loc = NULL;

            $ret = [
                'ret' => 2,
                'status' => 'Invalid parameters'
            ];

            if ($ip && $location) {
                # We might have a postcode search.  The AdView postcode search is unreliable, so we need to find
                # the nearest city.

                if ($me) {
                    list ($lat, $lng, $loc) = $me->getLatLng();

                    if ($loc == $location) {
                        # We are searching on our own location.
                        $location = $me->getCity()[0];
                    }
                }

                $url = "https://uk.whatjobs.com/api/v1/jobs.json?publisher=2053&channel=web&limit=50&radius=5&user_ip=$ip&location=" . urlencode($location);

                $ctx = stream_context_create(array('http'=> [
                    'timeout' => 10,
                    "method" => "GET"
                ]));

                $data = @file_get_contents($url, FALSE, $ctx);

                $ret = [
                    'ret' => 3,
                    'status' => 'No data returned'
                ];

                if ($data) {
                    $d = json_decode($data, TRUE);

                    $ret = [
                        'ret' => 4,
                        'status' => 'Data returned has unexpected format.'
                    ];

                    if (array_key_exists('data', $d)) {
                        $a = new AdView($dbhr, $dbhm);
                        $d['data'] = $a->sortJobs($d['data'], $me ? $me->getId() : NULL);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'adview' => $d,
                            'url' => $url,
                            'ip' => $ip,
                            'user_agent' => Utils::presdef('User-Agent', $hdrs, NULL),
                            'searchedloc' => $location,
                            'ownlocation' => $loc
                        ];
                    }
                }
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
