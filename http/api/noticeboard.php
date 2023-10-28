<?php
namespace Freegle\Iznik;

function noticeboard() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = (Utils::presint('id', $_REQUEST, NULL));

    $n = new Noticeboard($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [
                'ret' => 2,
                'status' => 'Invalid id'
            ];

            if ($id) {
                if ($n->getId() == $id) {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'noticeboard' => $n->getPublic()
                    ];

                    unset($ret['noticeboard']['position']);
                }
            } else {
                $authorityid = Utils::presint('authorityid', $_REQUEST, NULL);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'noticeboards' => $n->listAll($authorityid)
                ];

                // TODO Remove after 2024-06-01
                if ($authorityid == 72950) {
                    // Return locations of members for Wandsworth.
                    $locs = [];
                    $members = $dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = 126719 AND added >= '2023-07-11' AND added < '2023-12-31';");
                    foreach ($members as $member) {
                        $u = new User($dbhr, $dbhm, $member['userid']);
                        list ($lat, $lng) = $u->getLatLng(FALSE, FALSE, Utils::BLUR_NONE);
                        if ($lat && $lng) {
                            $contained = $dbhr->preQuery("SELECT ST_Contains(polygon, ST_SRID(POINT($lng, $lat), {$dbhr->SRID()})) AS contained FROM authorities WHERE id = 72950;");

                            if ($contained[0]['contained']) {
                                // Only include users who are in the authority.
                                $locs[] = [
                                    'lat' => $lat,
                                    'lng' => $lng
                                ];
                            }
                        }
                    }

                    $ret['members'] = $locs;
                }

            }
            break;
        }

        case 'POST': {
            $name = Utils::presdef('name', $_REQUEST, NULL);
            $lat = Utils::presfloat('lat', $_REQUEST, NULL);
            $lng = Utils::presfloat('lng', $_REQUEST, NULL);
            $description = Utils::presdef('description', $_REQUEST, NULL);
            $action = Utils::presdef('action', $_REQUEST, NULL);

            $ret = [
                'ret' => 2,
                'status' => 'Create failed'
            ];

            if ($action) {
                $me = Session::whoAmI($dbhr, $dbhm);
                $n->action($id, $me ? $me->getId() : NULL, $action, Utils::presdef('comments', $_REQUEST, NULL));

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
            } else if ($lat || $lng) {
                $id = $n->create($name, $lat, $lng, $me ? $me->getId() : NULL, $description);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $id
                ];
            }
            break;
        }

        case 'PATCH': {
            $id = $n->setAttributes($_REQUEST);

            $photoid = Utils::presint('photoid', $_REQUEST, NULL);

            if ($photoid) {
                $n->setPhoto($photoid);
            }

            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'id' => $id
            ];
            break;
        }
    }

    return($ret);
}
