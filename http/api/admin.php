<?php
namespace Freegle\Iznik;

function admin() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = Utils::presint('id', $_REQUEST, NULL);
    $groupid = Utils::presint('groupid', $_REQUEST, NULL);
    $a = new Admin($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($id) {
                # We're not bothered about privacy of admins - people may not be logged in when they see them.
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'admin' => $a->getPublic()
                ];
            } else if ($me && $groupid) {
                # We want to list the admins for this group.
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'admins' => $a->listForGroup($groupid)
                ];
            } else if ($me) {
                # Get all pending for this user.
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'admins' => $a->listPending($me->getId())
                ];
            }
            break;
        }

        case 'POST': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];
            $action = Utils::presdef('action', $_REQUEST, NULL);

            if ($me) {
                if ($action == 'Hold') {
                    $a->hold();
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'Release') {
                    $a->release();
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else {
                    $ret = ['ret' => 2, 'status' => "Can't create an admin on that group" ];
                    $subject = Utils::presdef('subject', $_REQUEST, NULL);
                    $text = Utils::presdef('text', $_REQUEST, NULL);

                    # Admin and Support can create suggested admins, which aren't attached to a group.
                    if ($me->isAdminOrSupport() || $me->isModOrOwner($groupid)) {
                        $ret = ['ret' => 3, 'status' => "Create failed" ];
                        $aid = $a->create($groupid, $me->getId(), $subject, $text);

                        if ($aid) {
                            $ret = [
                                'ret' => 0,
                                'status' => 'Success',
                                'id' => $aid
                            ];
                        }
                    }
                }
            }
            break;
        }

        case 'PATCH': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $ret = ['ret' => 2, 'status' => "Can't create an admin on that group" ];

                #error_log("Check mod for admin $id , group " . $a->getPrivate('groupid'));
                if ($me->isModOrOwner($a->getPrivate('groupid'))) {
                    $a->setAttributes($_REQUEST);
                    $a->updateEdit();

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                    ];
                }
            }
            break;
        }

        case 'DELETE': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $ret = ['ret' => 2, 'status' => "Can't create an admin on that group" ];

                #error_log("Check mod for admin $id , group " . $a->getPrivate('groupid'));
                if ($me->isModOrOwner($a->getPrivate('groupid'))) {
                    $a->delete();

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                    ];
                }
            }
            break;
        }
    }

    return($ret);
}
