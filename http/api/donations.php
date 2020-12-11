<?php
namespace Freegle\Iznik;

function donations() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));

    switch ($_REQUEST['type']) {
        case 'GET': {
            $d = new Donations($dbhr, $dbhm, $groupid);
            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'donations' => $d->get()
            ];

            break;
        }
    }

    return($ret);
}
