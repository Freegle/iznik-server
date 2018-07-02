<?php
function team() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $id = intval(presdef('id', $_REQUEST, NULL));

    $t = new Team($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($id) {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'team' => $t->getPublic()
                ];

                $ret['team']['members'] = [];
                $membs = $t->getMembers();

                if ($membs) {
                    $members = [];
                    foreach ($membs as $memb) {
                        $u = User::get($dbhr, $dbhm, $memb['userid']);
                        $ctx = NULL;
                        $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
                        $u->ensureAvatar($atts);

                        if ($memb['nameoverride']) {
                            $atts['displayname'] = $memb['nameoverride'];
                        }

                        if ($memb['imageoverride']) {
                            $atts['profile']['url'] = $memb['imageoverride'];
                            $atts['profile']['turl'] = $memb['imageoverride'];
                        }

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
                $name = presdef('name', $_REQUEST, NULL);
                $desc = presdef('description', $_REQUEST, NULL);
                $id = NULL;

                if ($name) {
                    $id = $t->create($name, $desc);
                }

                $ret = $id ? ['ret' => 0, 'status' => 'Success', 'id' => $id] : ['ret' => 2, 'status' => 'Create failed'];
            }

            break;
        }

        case 'PATCH': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me && $me->hasPermission(User::PERM_TEAMS)) {
                $t->setAttributes($_REQUEST);
                $userid = intval(presdef('userid', $_REQUEST, NULL));
                $desc = presdef('description', $_REQUEST, NULL);

                switch (presdef('action', $_REQUEST, NULL)) {
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
