<?php
function adview() {
    # This proxies a request on to adview to avoid CORS issues.
    global $dbhr, $dbhm;

    $ip = presdef('REMOTE_ADDR', $_SERVER, NULL);
    $hdrs = getallheaders();
    if (pres('X-Real-Ip', $hdrs)) {
        // Passed using proxy protocol
        $ip = $hdrs['X-Real-Ip'];
    }

    $location = presdef('location', $_REQUEST, NULL);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $data = NULL;

            if ($ip && $location) {
                $url = "https://adview.online/api/v1/jobs.json?publisher=2053&channel=web&limit=50&radius=5&user_ip=$ip&location=" . urlencode($_REQUEST['location']);

                $ctx = stream_context_create(array('http'=> [
                    'timeout' => 10,
                    "method" => "GET"
                ]));

                $data = file_get_contents($url, FALSE, $ctx);

                if ($data) {
                    $d = json_decode($data, TRUE);
                    if (array_key_exists('data', $d)) {
                        shuffle($d['data']);
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'adview' => $d,
//                            'ip' => $ip,
//                            'req' => $_REQUEST,
//                            'srv' => $_SERVER,
//                            'headers' => getallheaders()
                        ];
                    } else {
                        $ret = [
                            'ret' => 4,
                            'status' => 'Data returned has unexpected format.'
                        ];
                    }
                } else {
                    $ret = [
                        'ret' => 3,
                        'status' => 'No data returned'
                    ];
                }
            } else {
                $ret = [
                    'ret' => 2,
                    'status' => 'Invalid parameters'
                ];
            }
            break;
        }
    }

    return($ret);
}
