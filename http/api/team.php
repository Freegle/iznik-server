<?php
namespace Freegle\Iznik;

function team() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = intval(Utils::presdef('id', $_REQUEST, NULL));
    $name = Utils::presdef('name', $_REQUEST, NULL);

    $t = new Team($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if (!$id && $name) {
                # See if we can find the team by name.
                $id = $t->findByName($name);
                $t = new Team($dbhr, $dbhm, $id);
            }

            if ($id || $name == Team::TEAM_VOLUNTEERS) {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'team' => $t->getPublic()
                ];

                $ret['team']['members'] = [];
                $membs = $name == Team::TEAM_VOLUNTEERS ? $t->getVolunteers() : $t->getMembers();

                if ($membs) {
                    $members = [];
                    foreach ($membs as $memb) {
                        if ($name == Team::TEAM_VOLUNTEERS) {
                            # We already have the atts we need, which were grabbed for performance inside the list.
                            $atts = $memb;
                        } else {
                            $u = User::get($dbhr, $dbhm, $memb['userid']);
                            $ctx = NULL;
                            $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);

                            $u->ensureAvatar($atts);
                        }

                        if (Utils::pres('nameoverride', $memb)) {
                            $atts['displayname'] = $memb['nameoverride'];
                        }

                        if (Utils::pres('imageoverride', $memb)) {
                            $atts['profile']['url'] = $memb['imageoverride'];
                            $atts['profile']['turl'] = $memb['imageoverride'];
                        }

                        $atts['description'] = Utils::presdef('description', $memb, NULL);
                        $atts['added'] = Utils::ISODate($memb['added']);

                        $members[] = $atts;
                    }

                    usort($members, function ($a, $b) {
                        return (strcmp(strtolower($a['displayname']), strtolower($b['displayname'])));
                    });

                    $ret['team']['members'] = $members;
                }
            } else {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'teams' => $t->listAll()
                ];
            }
            break;
        }

        case 'POST': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me && $me->hasPermission(User::PERM_TEAMS)) {
                $name = Utils::presdef('name', $_REQUEST, NULL);
                $desc = Utils::presdef('description', $_REQUEST, NULL);
                $email = Utils::presdef('email', $_REQUEST, NULL);
                $id = NULL;

                if ($name) {
                    $id = $t->create($name, $email, $desc);
                }

                $ret = $id ? ['ret' => 0, 'status' => 'Success', 'id' => $id] : ['ret' => 2, 'status' => 'Create failed'];
            }

            break;
        }

        case 'PATCH': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me && $me->hasPermission(User::PERM_TEAMS)) {
                $t->setAttributes($_REQUEST);
                $userid = intval(Utils::presdef('userid', $_REQUEST, NULL));
                $desc = Utils::presdef('description', $_REQUEST, NULL);

                switch (Utils::presdef('action', $_REQUEST, NULL)) {
                    case 'Add': $t->addMember($userid, $desc); break;
                    case 'Remove': $t->removeMember($userid); break;
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
            }
            break;
        }

        case 'DELETE': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me && $me->hasPermission(User::PERM_TEAMS)) {
                $t->delete();

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
            }
            break;
        }
    }

    return($ret);
}
