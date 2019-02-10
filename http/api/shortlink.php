<?php
function shortlink() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $me = whoAmI($dbhr, $dbhm);

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];

            $id = intval(presdef('id', $_REQUEST, 0));

            if ($id) {
                $s = new Shortlink($dbhr, $dbhm, $id);
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'shortlink' => $s->getPublic()
                ];
            } else if ($me && $me->isModerator()) {
                $s = new Shortlink($dbhr, $dbhm);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'shortlinks' => $s->listAll()
                ];
            }

            break;
        }

        case 'POST': {
            $name = presdef('name', $_REQUEST, NULL);
            $groupid = intval(presdef('groupid', $_REQUEST, 0));

            $ret = ['ret' => 2, 'status' => 'Invalid parameters'];

            if ($name && $groupid) {
                $s = new Shortlink($dbhr, $dbhm);

                list($id, $url) = $s->resolve($name, FALSE);

                if ($id) {
                    $ret = ['ret' => 3, 'status' => 'Name already in use'];
                } else {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'id' => $s->create($name, Shortlink::TYPE_GROUP, $groupid)
                    ];
                }
            }
        }
    }

    return($ret);
}
