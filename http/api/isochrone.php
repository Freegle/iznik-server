<?php
namespace Freegle\Iznik;

function isochrone() {
    global $dbhr, $dbhm;

    $myid = Session::whoAmId($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $transport = (Utils::pres('transport', $_REQUEST, Isochrone::DRIVE));
        $minutes = (Utils::presint('minutes', $_REQUEST, NULL));
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $i = new Isochrone($dbhr, $dbhm);
                $id = $i->find($myid, $transport, $minutes);

                if (!$id) {
                    # No existing one - create it.
                    $i->create($myid, $transport, $minutes);
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'isochrone' => $i->getPublic()
                ];
                break;
            }
        }
    }

    return($ret);
}
