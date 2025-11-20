<?php
namespace Freegle\Iznik;

function isochrone() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);
    $myid = $me ? $me->getId() : NULL;

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $i = new Isochrone($dbhr, $dbhm);
                $all = Utils::presbool('all', $_REQUEST, FALSE);
                $isochrones = $i->list($myid, $all && $myid && $me->isAdminOrSupport() ? TRUE : FALSE);

                if (!count($isochrones)) {
                    # No existing one - create a default one.
                    $id = $i->create($myid, NULL, Isochrone::DEFAULT_TIME, NULL, NULL);

                    if ($id) {
                        $i = new Isochrone($dbhr, $dbhm, $id);
                        $isochrones = [
                            $i->getPublic()
                        ];
                    }
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
                $minutes = Utils::presint('minutes', $_REQUEST, NULL);
                $transport = Utils::presdef('transport', $_REQUEST, NULL);

                $i = new Isochrone($dbhr, $dbhm, $id);
                $newId = $i->edit($minutes, $transport);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $newId
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

                if ($locationid && $minutes) {
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
                        $i->decoupleFromUser();

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
