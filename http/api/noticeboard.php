<?php
function noticeboard() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $id = intval(presdef('id', $_REQUEST, NULL));

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
            $name = presdef('name', $_REQUEST, NULL);
            $lat = pres('lat', $_REQUEST) ? floatval($_REQUEST['lat']) : NULL;
            $lng = pres('lng', $_REQUEST) ? floatval($_REQUEST['lng']) : NULL;
            $description = presdef('description', $_REQUEST, NULL);
            $action = presdef('action', $_REQUEST, NULL);

            $ret = [
                'ret' => 2,
                'status' => 'Create failed'
            ];

            if ($action) {
                $me = whoAmI($dbhr, $dbhm);
                $n->action($id, $me ? $me->getId() : NULL, $action, presdef('comments', $_REQUEST, NULL));
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
