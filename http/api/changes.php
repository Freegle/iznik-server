<?php
namespace Freegle\Iznik;

function changes() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $since = Utils::presdef('since', $_REQUEST, date("Y-m-d H:i:s", strtotime("1 hour ago")));
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $partner = Utils::pres('partner', $_SESSION);

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [
                'ret' => 2,
                'status' => 'Invalid parameters'
            ];

            if ($since) {
                $m = new MessageCollection($dbhr, $dbhm);
                $u = new User($dbhr, $dbhm);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'changes' => [
                        'messages' => $m->getChanges($since),
                        'users' => $u->getChanges($since),
                        'ratings' => $partner ? $u->getAllRatings($since) : []
                    ]
                ];
            }
            break;
        }
    }

    return($ret);
}
