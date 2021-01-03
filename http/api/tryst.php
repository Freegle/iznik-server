<?php
namespace Freegle\Iznik;

function tryst() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = Utils::presint('id', $_REQUEST, NULL);
    $user1 = Utils::presint('user1', $_REQUEST, NULL);
    $user2 = Utils::presint('user2', $_REQUEST, NULL);
    $arrangedfor = Utils::presdef('arrangedfor', $_REQUEST, NULL);

    $t = new Tryst($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                if ($id) {
                    $ret = ['ret' => 2, 'status' => 'Permission denied'];

                    if ($t->canSee($me->getId())) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'tryst' => $t->getPublic($me->getId())
                        ];
                    }
                } else {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'trysts' => $t->listForUser($me->getId())
                    ];
                }
            }
            break;
        }

        case 'PUT': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $ret = ['ret' => 3, 'status' => 'Invalid parameters'];

                if ($user1 && $user2 && $arrangedfor && $user1 != $user2) {
                    $id = $t->create($user1, $user2, $arrangedfor);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'id' => $id
                    ];
                }
            }

            break;
        }

        case 'PATCH': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $ret = ['ret' => 2, 'status' => 'Permission denied'];

                if ($t->canSee($me->getId())) {
                    $t->setPrivate('arrangedfor', $arrangedfor);
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
            }

            break;
        }

        case 'POST': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $ret = ['ret' => 2, 'status' => 'Permission denied'];

                if ($t->canSee($me->getId())) {
                    $confirm = Utils::presbool('confirm', $_REQUEST, FALSE);
                    $decline = Utils::presbool('decline', $_REQUEST, FALSE);

                    if ($confirm) {
                        $t->confirm();
                    } else if ($decline) {
                        $t->decline();
                    }

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
            }

            break;
        }

        case 'DELETE': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me && $t->canSee($me->getId())) {
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
