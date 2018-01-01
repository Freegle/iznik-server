<?php
function visualise() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    $swlat = presdef('swlat', $_REQUEST, NULL);
    $swlng = presdef('swlng', $_REQUEST, NULL);
    $nelat = presdef('nelat', $_REQUEST, NULL);
    $nelng = presdef('nelng', $_REQUEST, NULL);
    $limit = intval(presdef($_REQUEST, 'limit', 100));
    $age = presdef('age', $_REQUEST, '7 days ago');

    switch ($_REQUEST['type']) {
        case 'GET': {
            $v = new Visualise($dbhr, $dbhm);

            $vs = $v->getMessages($swlat, $swlng, $nelat, $nelng, $age, $limit);

            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'list' => $vs
            ];
            break;
        }
    }

    return($ret);
}
