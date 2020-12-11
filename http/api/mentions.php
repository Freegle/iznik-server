<?php
namespace Freegle\Iznik;

function mentions() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($me) {
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $query = Utils::presdef('query', $_REQUEST, NULL);
                $id = (Utils::presint('id', $_REQUEST, NULL));
                $ret = [ 'ret' => 2, 'status' => 'Invalid parameters' ];

                if ($id) {
                    $n = new Newsfeed($dbhr, $dbhm, $id);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'mentions' => $n->mentions($me->getId(), $query)
                    ];
                }

                break;
            }
        }
    }

    return($ret);
}
