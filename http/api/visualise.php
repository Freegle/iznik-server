<?php
function visualise() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    $swlat = presdef('swlat', $_REQUEST, NULL);
    $swlng = presdef('swlng', $_REQUEST, NULL);
    $nelat = presdef('nelat', $_REQUEST, NULL);
    $nelng = presdef('nelng', $_REQUEST, NULL);
    $limit = intval(presdef($_REQUEST, 'limit', 5));
    $ctx = presdef('context', $_REQUEST, NULL);

    switch ($_REQUEST['type']) {
        case 'GET': {
            $v = new Visualise($dbhr, $dbhm);

            $vs = $v->getMessages($swlat, $swlng, $nelat, $nelng, $limit, $ctx);

            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'list' => $vs,
                'context' => $ctx
            ];
            break;
        }
    }

    return($ret);
}
