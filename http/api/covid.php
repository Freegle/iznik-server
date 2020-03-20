<?php
function covid() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $helptype = presdef('helptype', $_REQUEST, NULL);
    $info = presdef('info', $_REQUEST, []);
    $id = intval(presdef('id', $_REQUEST, NULL));

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 2, 'status' => 'Permission denied' ];
            if ($me && $me->isModerator()) {
                if ($id) {
                    $covids = $dbhr->preQuery("SELECT * FROM covid WHERE userid = ?;", [
                        $id
                    ]);

                    foreach ($covids as $covid) {
                        $u = new User($dbhr, $dbhm, $covid['userid']);

                        # Get best guess location.
                        list ($lat, $lng) = $u->getLatLng();

                        if ($lat || $lng) {
                            $helpq = '';

                            if ($covid['info']) {
                                $helpneeded = json_decode($covid['info'], TRUE);

                                foreach ($helpneeded as $need => $val) {
                                    if ($need != 'other' && $val) {
                                        if (!$helpq) {
                                            $helpq = "WHERE JSON_EXTRACT(info, '\$.$need')";
                                        } else {
                                            $helpq .= " OR JSON_EXTRACT(info, '\$.$need') ";
                                        }
                                    }
                                }
                            }

                            # Find helpful users nearby.
                            $sql = "SELECT users.id, locations.lat, locations.lng, haversine($lat, $lng, locations.lat, locations.lng) AS dist, users_kudos.kudos FROM users 
    INNER JOIN covid ON covid.userid = users.id AND covid.type = 'CanHelp' 
    INNER JOIN locations ON locations.id = users.lastlocation 
    LEFT JOIN users_kudos ON users.id = users_kudos.userid
    $helpq
    ORDER BY dist ASC LIMIT 10;";

                            $helpers = $dbhr->preQuery($sql);

                            # Get their info.
                            $uids = array_column($helpers, 'id');
                            $u = new User($dbhr, $dbhm);
                            $users = $u->getPublicsById($uids, NULL, TRUE, FALSE, $ctx, FALSE, TRUE, FALSE, FALSE, FALSE, [MessageCollection::APPROVED], FALSE);
                            $u->getPublicLocations($users);
                            $locs = $u->getLatLngs($users, FALSE, FALSE, FALSE, NULL);

                            foreach ($users as $userid => $user) {
                                if (pres($userid, $locs)) {
                                    $users[$userid]['privateposition'] = $locs[$userid];
                                }
                            }

                            # Add kudos for client sorting.
                            foreach ($helpers as $helper) {
                                $users[$helper['id']]['kudos'] = $helper['kudos'];
                                $users[$helper['id']]['distance'] = $helper['dist'];
                            }

                            # Add the helper's covid info.
                            $helpercovids = $dbhr->preQuery("SELECT * FROM covid WHERE userid IN (" . implode(', ', $uids) . ")");

                            foreach ($helpercovids as $key => $t) {
                                $users[$t['userid']]['covid'] = $helpercovids[$key];
                            }

                            $u->getInfos($users);

                            $covid['helpers'] = $users;

                            return [
                                'ret' => 0,
                                'status' => 'Success',
                                'covid' => $covid,
                                'id' => $id
                            ];
                        }
                    }
                } else {
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
            break;
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
                foreach ([ 'contacted', 'closed', 'comments', 'phone', 'intro' ] as $att) {
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
