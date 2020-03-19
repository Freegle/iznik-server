<?php
function covid() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $helptype = presdef('helptype', $_REQUEST, NULL);
    $info = presdef('info', $_REQUEST, []);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 2, 'status' => 'Permission denied' ];
            if ($me && $me->isModerator()) {
                $groupid = intval(presdef('groupid', $_REQUEST, NULL));

                if ($groupid < 0 && $me->isAdminOrSupport()) {
                    $sql = "SELECT * FROM covid;";
                } else {
                    if (!$groupid) {
                        $groupids = $me->getModeratorships();
                    } else {
                        $groupids = [$groupid];
                    }

                    $sql = "SELECT DISTINCT covid.* FROM covid INNER JOIN memberships ON covid.userid = memberships.userid WHERE groupid IN (" . implode(',', $groupids) . ");";
                }

                $covids = $dbhr->preQuery($sql);
                $uids = array_column($covids, 'userid');

                foreach ($covids as $key => $covid) {
                    $covids[$key]['id'] = $covid['userid'];
                }

                $u = new User($dbhr, $dbhm);
                $users = $u->getPublicsById($uids, NULL, TRUE, FALSE, $ctx, FALSE, TRUE, FALSE, FALSE, FALSE, [MessageCollection::APPROVED], FALSE);
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
                $dbhm->preExec("INSERT INTO covid (userid, type, info) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE type = ?, info = ?;", [
                    $me->getId(),
                    $helptype,
                    json_encode($info),
                    $helptype,
                    json_encode($info)
                ]);

                $ret = [ 'ret' => 0, 'status' => 'Success' ];
            }
            break;
        }

        case 'PATCH': {
            $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];
            $id = intval(presdef('id', $_REQUEST, 0));

            if ($me && $me->isModerator() && $id) {
                foreach ([ 'contacted', 'closed', 'comments' ] as $att) {
                    if (array_key_exists($att, $_REQUEST)) {
                        $dbhm->preExec("UPDATE covid SET $att = ? WHERE userid = ?", [
                            $_REQUEST[$att],
                            $id
                        ]);
                    }
                }

                $ret = [ 'ret' => 0, 'status' => 'Success' ];
            }

            break;
        }
    }

    return($ret);
}
