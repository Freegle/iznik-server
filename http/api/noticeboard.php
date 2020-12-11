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
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'noticeboards' => $n->listAll()
                ];
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
