<?php
namespace Freegle\Iznik;

function isochrone() {
    global $dbhr, $dbhm;

    $myid = Session::whoAmId($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $transport = (Utils::presdef('transport', $_REQUEST, Isochrone::DRIVE));
        $minutes = (Utils::presint('minutes', $_REQUEST, 30));
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $i = new Isochrone($dbhr, $dbhm);
                $id = $i->find($myid, $transport, $minutes);

                if (!$id) {
                    # No existing one - create it.
                    $id = $i->create($myid, $transport, $minutes);
                }

                $i = new Isochrone($dbhr, $dbhm, $id);

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
