<?php
namespace Freegle\Iznik;

function locations() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = (Utils::presint('id', $_REQUEST, NULL));
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));
    $messageid = (Utils::presint('messageid', $_REQUEST, NULL));
    $action = Utils::presdef('action', $_REQUEST, NULL);
    $byname = array_key_exists('byname', $_REQUEST) ? filter_var($_REQUEST['byname'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $groupsnear = array_key_exists('groupsnear', $_REQUEST) ? filter_var($_REQUEST['groupsnear'], FILTER_VALIDATE_BOOLEAN) : TRUE;
    $groupcount = array_key_exists('groupcount', $_REQUEST) ? filter_var($_REQUEST['groupcount'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $pconly = array_key_exists('pconly', $_REQUEST) ? filter_var($_REQUEST['pconly'], FILTER_VALIDATE_BOOLEAN) : TRUE;

    $l = new Location($dbhr, $dbhm, $id);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $lat = Utils::presfloat('lat', $_REQUEST, NULL);
            $lng = Utils::presfloat('lng', $_REQUEST, NULL);
            $swlat = Utils::presfloat('swlat', $_REQUEST, NULL);
            $swlng = Utils::presfloat('swlng', $_REQUEST, NULL);
            $nelat = Utils::presfloat('nelat', $_REQUEST, NULL);
            $nelng = Utils::presfloat('nelng', $_REQUEST, NULL);
            $typeahead = Utils::presdef('typeahead', $_REQUEST, NULL);
            $limit = (Utils::presint('limit', $_REQUEST, 10));

            if ($lat && $lng) {
                $ret = [ 'ret' => 0, 'status' => 'Success', 'location' => $l->closestPostcode($lat, $lng) ];
            } else if ($typeahead) {
                $ret = [ 'ret' => 0, 'status' => 'Success', 'locations' => $l->typeahead($typeahead, $limit, $groupsnear, $pconly) ];

                if ($groupcount && count($ret['locations']) == 1) {
                    foreach ($ret['locations'][0]['groupsnear'] as &$group) {
                        $group['postcount'] = Group::getOpenCount($dbhr, $group['id']);
                    }
                }
            } else if ($swlat || $swlng || $nelat || $nelng) {
                $ret = [ 'ret' => 0, 'status' => 'Success', 'locations' => $l->withinBox($swlat, $swlng, $nelat, $nelng) ];
            }
            break;
        }

        case 'POST': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];
            $role = $me ? $me->getRoleForGroup($groupid) : User::ROLE_NONMEMBER;

            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                $ret = [ 'ret' => 0, 'status' => 'Success' ];

                switch ($action) {
                    case 'Exclude':
                        $l->exclude($groupid, $me->getId(), $byname);

                        if ($messageid) {
                            # Suggest a new subject for this message.
                            $m = new Message($dbhr, $dbhm, $messageid);
                            $m->setPrivate('suggestedsubject', $m->suggestSubject($groupid, $m->getSubject()));
                            $ret['message'] = $m->getPublic(FALSE, FALSE);
                            $m->setPrivate('locationid', $ret['message']['location']['id']);
                        }

                        break;
                }
            }

            break;
        }

        case 'PATCH': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];
            $role = $me ? $me->getPrivate('systemrole') : User::ROLE_NONMEMBER;

            if ($role == User::SYSTEMROLE_MODERATOR || $role == User::SYSTEMROLE_SUPPORT || $role == User::SYSTEMROLE_ADMIN) {
                $polygon = Utils::presdef('polygon', $_REQUEST, NULL);
                if ($polygon) {
                    $worked = FALSE;
                    if ($l->setGeometry($polygon)) {
                        $worked = TRUE;
                    }
                }

                $name = Utils::presdef('name', $_REQUEST, NULL);
                if ($name) {
                    $l->setPrivate('name', $name);
                }

                if ($worked) {
                    $ret = [ 'ret' => 0, 'status' => 'Success' ];
                }
            }

            break;
        }

        case 'PUT': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];
            $role = $me ? $me->getPrivate('systemrole') : User::ROLE_NONMEMBER;

            if ($role == User::SYSTEMROLE_MODERATOR || $role == User::SYSTEMROLE_SUPPORT || $role == User::SYSTEMROLE_ADMIN) {
                $polygon = Utils::presdef('polygon', $_REQUEST, NULL);
                $name = Utils::presdef('name', $_REQUEST, NULL);

                # This parameter is used in UT.
                $osmparentsonly = array_key_exists('osmparentsonly', $_REQUEST) ? $_REQUEST['osmparentsonly'] : 1;

                if ($polygon && $name) {
                    # We create this as a place, which can be used as an area - the client wouldn't have created it
                    # if they didn't want that.
                    $id = $l->create(NULL, $name, 'Polygon', $polygon, $osmparentsonly, TRUE);
                    $ret = [ 'ret' => 0, 'status' => 'Success', 'id' => $id ];
                }
            }

            break;
        }
    }

    return($ret);
}
