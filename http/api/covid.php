<?php
function covid() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $helptype = presdef('helptype', $_REQUEST, NULL);
    $info = presdef('info', $_REQUEST, []);
    $id = intval(presdef('id', $_REQUEST, NULL));
    $userid = intval(presdef('userid', $_REQUEST, NULL));
    $counts = presdef('counts', $_REQUEST, NULL);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 2, 'status' => 'Permission denied' ];
            if ($me) {
                if ($counts) {
                    # Just a summary
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'counts' => [
                            'NeedHelp' => 0,
                            'CanHelp' => 0
                        ]
                    ];

                    $groupid = intval(presdef('groupid', $_REQUEST, NULL));

                    if ($groupid < 0 && $me->isAdminOrSupport()) {
                        $groupq = '';
                    } else {
                        if (!$groupid) {
                            $groupids = $me->getModeratorships();
                        } else {
                            $groupids = [$groupid];
                        }

                        $groupq = " INNER JOIN memberships ON covid.userid = memberships.userid AND groupid IN (" . implode(',', $groupids) . ") ";
                    }


                    # Top-level by type
                    $covids = $dbhr->preQuery("SELECT COUNT(DISTINCT covid.userid) AS count, type FROM covid INNER JOIN users ON users.id = covid.userid AND deleted IS NULL $groupq GROUP BY type;");

                    foreach ($covids as $count) {
                        $ret['counts'][$count['type']] = $count['count'];
                    }

                    # By status
                    $counts = $dbhr->preQuery("SELECT COUNT(DISTINCT covid.userid) AS count FROM covid INNER JOIN users ON users.id = covid.userid AND deleted IS NULL $groupq WHERE closed IS NOT NULL;");
                    $ret['counts']['closed'] = $counts[0]['count'];
                    $counts = $dbhr->preQuery("SELECT COUNT(DISTINCT covid.userid) AS count FROM covid INNER JOIN users ON users.id = covid.userid AND deleted IS NULL  $groupq WHERE closed IS NOT NULL AND type = 'NeedHelp';");
                    $ret['counts']['closedNeedHelp'] = $counts[0]['count'];
                    $counts = $dbhr->preQuery("SELECT COUNT(DISTINCT covid.userid) AS count FROM covid INNER JOIN users ON users.id = covid.userid AND deleted IS NULL  $groupq WHERE closed IS NOT NULL AND type = 'CanHelp';");
                    $ret['counts']['closedCanHelp'] = $counts[0]['count'];
                    $counts = $dbhr->preQuery("SELECT COUNT(DISTINCT covid.userid) AS count FROM covid INNER JOIN users ON users.id = covid.userid AND deleted IS NULL  $groupq WHERE dispatched IS NOT NULL AND viewedown >= dispatched;");
                    $ret['counts']['viewedown'] = $counts[0]['count'];
                    $counts = $dbhr->preQuery("SELECT COUNT(DISTINCT covid.userid) AS count FROM covid INNER JOIN users ON users.id = covid.userid AND deleted IS NULL  $groupq WHERE dispatched IS NOT NULL AND (viewedown IS NULL OR viewedown < dispatched);");
                    $ret['counts']['dispatched'] = $counts[0]['count'];
                } else if ($id || $userid) {
                    if ($userid) {
                        $covids = $dbhr->preQuery("SELECT * FROM covid WHERE userid = ?;", [
                            $userid
                        ]);
                    } else {
                        $covids = $dbhr->preQuery("SELECT * FROM covid WHERE id = ?;", [
                            $id
                        ]);
                    }

                    foreach ($covids as $covid) {
                        if ($me->isModerator() || $me->getId() == $covid['userid']) {
                            if ($me->getId() == $covid['userid']) {
                                # Viewing own suggestions - track.
                                $dbhm->preExec("UPDATE covid SET viewedown = NOW() WHERE id = ?", [
                                    $covid['id']
                                ]);
                            }

                            $u = new User($dbhr, $dbhm, $covid['userid']);

                            # Get best guess location.
                            list ($lat, $lng) = $u->getLatLng(TRUE, TRUE, TRUE);

                            $helpq = '';

                            if ($covid['info']) {
                                $helpneeded = json_decode($covid['info'], TRUE);

                                foreach ($helpneeded as $need => $val) {
                                    if ($need != 'other' && $val) {
                                        if (!$helpq) {
                                            $helpq = "AND (JSON_EXTRACT(info, '\$.$need')";
                                        } else {
                                            $helpq .= " OR JSON_EXTRACT(info, '\$.$need') ";
                                        }
                                    }
                                }

                                $helpq = $helpq ? "$helpq)" : '';
                            }

                            $ctx = NULL;
                            $covid['user'] = $u->getPublic(NULL, TRUE, FALSE, $ctx, FALSE, TRUE, FALSE, FALSE, FALSE, [MessageCollection::APPROVED], FALSE);
                            $covid['user']['settings'] = NULL;
                            $covid['user']['publiclocation'] = $u->getPublicLocation();
                            $covid['user']['privateposition'] = $u->getLatLng(FALSE, TRUE, TRUE);

                            $users = [];

                            if ($lat || $lng) {
                                # Find helpful users nearby.
                                $sql = "SELECT users.id, locations.lat, locations.lng, haversine($lat, $lng, locations.lat, locations.lng) AS dist, users_kudos.kudos FROM users 
INNER JOIN covid ON covid.userid = users.id AND covid.type = 'CanHelp' 
INNER JOIN locations ON locations.id = users.lastlocation 
LEFT JOIN users_kudos ON users.id = users_kudos.userid
WHERE users.deleted IS NULL $helpq
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

                                    $users[$userid]['settings'] = NULL;
                                }

                                # Add kudos for client sorting.
                                foreach ($helpers as $helper) {
                                    $users[$helper['id']]['kudos'] = $helper['kudos'];
                                    $users[$helper['id']]['distance'] = $helper['dist'];

                                    # Are they selected for this one?
                                    $selected = $dbhr->preQuery("SELECT * FROM covid_matches WHERE helpee = ? AND helper = ?", [
                                        $covid['userid'],
                                        $helper['id']
                                    ]);

                                    foreach ($selected as $s) {
                                        $users[$helper['id']]['selected'] = TRUE;
                                    }
                                }

                                # Add the helper's covid info.
                                $helpercovids = $dbhr->preQuery("SELECT * FROM covid WHERE userid IN (" . implode(', ', $uids) . ")");

                                foreach ($helpercovids as $key => $t) {
                                    $users[$t['userid']]['covid'] = $helpercovids[$key];
                                }

                                # Add a count of how many matches we have, to avoid overloading.
                                $sql = "SELECT COUNT(*) AS count, helper FROM covid_matches WHERE helper IN (" . implode(', ', $uids) . ") GROUP BY helper";
                                error_log($sql);
                                $matches = $dbhr->preQuery($sql);

                                foreach ($matches as $key => $t) {
                                    error_log("{$t['helper']} => {$t['count']}");
                                    $users[$t['helper']]['covid']['selectcount'] = $t['count'];
                                }

                                $u->getInfos($users);
                            }

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
                        $sql = "SELECT covid.* FROM covid INNER JOIN users ON covid.userid = users.id WHERE covid.type = ? AND users.deleted IS NULL;";
                    } else {
                        if (!$groupid) {
                            $groupids = $me->getModeratorships();
                        } else {
                            $groupids = [$groupid];
                        }

                        $sql = "SELECT DISTINCT covid.* FROM covid INNER JOIN memberships ON covid.userid = memberships.userid WHERE groupid IN (" . implode(',', $groupids) . ") AND covid.type = ?;";
                    }

                    $covids = $dbhr->preQuery($sql, [
                        $helptype
                    ]);

                    $uids = array_column($covids, 'userid');

                    $u = new User($dbhr, $dbhm);
                    $users = $u->getPublicsById($uids, NULL, TRUE, FALSE, $ctx, FALSE, TRUE, FALSE, FALSE, FALSE, [MessageCollection::APPROVED], FALSE);
                    $u->getPublicLocations($users);

                    $locs = $u->getLatLngs($users, FALSE, FALSE, FALSE, NULL, TRUE);

                    foreach ($users as $userid => $user) {
                        if (pres($userid, $locs)) {
                            $users[$userid]['privateposition'] = $locs[$userid];
                        }

                        $users[$userid]['settings'] = NULL;
                    }

                    foreach ($covids as $key => $covid) {
                        $covids[$key]['user'] = $users[$covid['userid']];
                    }

                    return [
                        'ret' => 0,
                        'status' => 'Success',
                        'covids' => $covids
                    ];
                }
            }
            break;
        }

        case 'POST': {
            $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

            if ($me && $me->isModerator()) {
                $helper = intval(presdef('helper', $_REQUEST, NULL));
                $helpee = intval(presdef('helpee', $_REQUEST, NULL));
                $action = presdef('action', $_REQUEST, NULL);

                switch ($action) {
                    case 'Suggest': {
                        $dbhm->preExec("INSERT IGNORE INTO covid_matches (helper, helpee, suggestedby) VALUES (?, ?, ?);", [
                            $helper,
                            $helpee,
                            $me->getId()
                        ]);

                        return [
                            'ret' => 0,
                            'status' => 'Success',
                        ];
                        break;
                    }
                    case 'Remove': {
                        $dbhm->preExec("DELETE FROM covid_matches WHERE helper = ? AND helpee = ?;", [
                            $helper,
                            $helpee
                        ]);

                        return [
                            'ret' => 0,
                            'status' => 'Success',
                        ];
                        break;
                    }
                    case 'Dispatch': {
                        $dbhm->preExec("UPDATE covid SET dispatched = NOW() WHERE id = ?;", [
                            $id
                        ]);

                        return [
                            'ret' => 0,
                            'status' => 'Success',
                            'dispatched' => TRUE
                        ];
                    }
                }
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

            if ($me) {
                if ($me->isModerator()) {
                    foreach ([ 'comments', 'closed' ] as $att) {
                        if (array_key_exists($att, $_REQUEST)) {
                            $dbhm->preExec("UPDATE covid SET $att = ? WHERE id = ?", [
                                $_REQUEST[$att],
                                $id
                            ]);
                        }
                    }
                }

                foreach ([ 'phone', 'intro' ] as $att) {
                    if (array_key_exists($att, $_REQUEST)) {
                        $dbhm->preExec("UPDATE covid SET $att = ? WHERE id = ?", [
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
