<?php
namespace Freegle\Iznik;

function isochrone() {
    global $dbhr, $dbhm;

    $myid = Session::whoAmId($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $i = new Isochrone($dbhr, $dbhm);
                $isochrones = $i->list($myid);

                if (!count($isochrones)) {
                    # No existing one - create a default one.
                    $id = $i->create($myid, NULL, Isochrone::DEFAULT_TIME);
                    $i = new Isochrone($dbhr, $dbhm, $id);
                    $isochrones = [
                        $i->getPublic()
                    ];
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'isochrones' => $isochrones
                ];
                break;
            }

            case 'PATCH': {
                $id = (Utils::presint('id', $_REQUEST, NULL));

                $i = new Isochrone($dbhr, $dbhm);
                $isochrones = $i->list($myid);

                foreach ($isochrones as $isochrone) {
                    if ($isochrone['id'] == $id) {
                        // If we change the transport/distance then we update the existing isochrone rather than
                        // generate a new one.  Otherwise we will get a clutter of them around a location as
                        // people experiment, and that will looks silly in the UI.
                        $i = new Isochrone($dbhr, $dbhm, $id);
                        $i->setAttributes($_REQUEST);
                        $i->refetch();
                    }
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
                break;
            }

            case 'PUT': {
                $minutes = Utils::presint('minutes', $_REQUEST, NULL);
                $transport = Utils::presdef('transport', $_REQUEST, NULL);
                $locationid = Utils::presint('locationid', $_REQUEST, NULL);
                $nickname = Utils::presdef('nickname', $_REQUEST, NULL);

                $ret = [
                    'ret' => 3,
                    'status' => 'Invalid parameters'
                ];

                if ($minutes && $transport && $nickname) {
                    $l = new Location($dbhr, $dbhm, $locationid);

                    if ($l->getId() == $locationid) {
                        $ret = [
                            'ret' => 4,
                            'status' => 'Create failed'
                        ];

                        $i = new Isochrone($dbhr, $dbhm);
                        $id = $i->create($myid, $transport, $minutes, $nickname, $locationid);

                        if ($id) {
                            $ret = [
                                'ret' => 0,
                                'status' => 'Success',
                                'id' => $id
                            ];
                        }
                    }
                }
                break;
            }

            case 'DELETE': {
                $id = (Utils::presint('id', $_REQUEST, NULL));

                $i = new Isochrone($dbhr, $dbhm);
                $isochrones = $i->list($myid);

                $ret = [
                    'ret' => 2,
                    'status' => 'Access denied'
                ];

                foreach ($isochrones as $isochrone)
                {
                    if ($isochrone['id'] == $id) {
                        $i = new Isochrone($dbhr, $dbhm, $id);
                        $i->delete();
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    }
                }
                break;
            }
        }
    }

    return($ret);
}
