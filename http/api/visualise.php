<?php
namespace Freegle\Iznik;

function visualise() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    $swlat = Utils::presdef('swlat', $_REQUEST, NULL);
    $swlng = Utils::presdef('swlng', $_REQUEST, NULL);
    $nelat = Utils::presdef('nelat', $_REQUEST, NULL);
    $nelng = Utils::presdef('nelng', $_REQUEST, NULL);
    $limit = intval(Utils::presdef('limit', $_REQUEST, 5));
    $ctx = Utils::presdef('context', $_REQUEST, NULL);

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
