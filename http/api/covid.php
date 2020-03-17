<?php
function covid() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $helptype = presdef('helptype', $_REQUEST, NULL);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 2, 'status' => 'Permission denied' ];
            if ($me && $me->isModerator()) {
                $covids = $dbhr->preQuery("SELECT * FROM covid;");
                $uids = array_column($covids, 'userid');

                foreach ($covids as $key => $covid) {
                    $covids[$key]['id'] = $covid['userid'];
                }

                $u = new User($dbhr, $dbhm);
                $users = $u->getPublicsById($uids);
                $u->getPublicLocations($users);

                $locs = $u->getLatLngs($users, FALSE, FALSE, FALSE, NULL);

                foreach ($covids as $key => $covid) {
                    $users[$covid['userid']]['covid'] = $covids[$key];
                }

                foreach ($users as $userid => $user) {
                    if (pres($userid, $locs)) {
                        $users[$userid]['privateposition'] = $locs[$userid];
                    }
                }

                return [
                    'ret' => 0,
                    'status' => 'Success',
                    'covids' => $users
                ];
            }
        }

        case 'PUT': {
            $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

            if ($me && $helptype) {
                $dbhm->preExec("INSERT INTO covid (userid, type) VALUES (?, ?) ON DUPLICATE KEY UPDATE type = ?;", [
                    $me->getId(),
                    $helptype,
                    $helptype
                ]);

                $ret = [ 'ret' => 0, 'status' => 'Success' ];
            }
            break;
        }
    }

    return($ret);
}
