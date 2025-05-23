<?php
namespace Freegle\Iznik;

function activity() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    $grouptype = Utils::presdef('grouptype', $_REQUEST, Group::GROUP_FREEGLE);

    switch ($_REQUEST['type']) {
        case 'GET': {
            $m = new MessageCollection($dbhr, $dbhm);
            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'recentmessages' => $m->getRecentMessages($grouptype)
            ];
        }
            break;
    }

    return($ret);
}
