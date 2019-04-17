<?php
function admin() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $id = presdef('id', $_REQUEST, NULL);
    $groupid = presdef('groupid', $_REQUEST, NULL);
    $id = $id ? intval($id) : NULL;
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

            if ($me) {
                $ret = ['ret' => 2, 'status' => "Can't create an admin on that group" ];
                $subject = presdef('subject', $_REQUEST, NULL);
                $text = presdef('text', $_REQUEST, NULL);

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
