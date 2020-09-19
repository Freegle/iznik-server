<?php
namespace Freegle\Iznik;

function comment() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $id = intval(Utils::presdef('id', $_REQUEST, NULL));
    $ctx = Utils::presdef('context', $_REQUEST, NULL);
    $userid = intval(Utils::presdef('userid', $_REQUEST, NULL));
    $groupid = intval(Utils::presdef('groupid', $_REQUEST, NULL));
    $user1 = $user2 = $user3 = $user4 = $user5 = $user6 = $user7 = $user8 = $user9 = $user10 = $user11 = NULL;

    for ($i = 1; $i <= 11; $i++) {
        ${"user$i"} = Utils::presdef("user$i", $_REQUEST, NULL);
    }

    $u = User::get($dbhr, $dbhm, $userid);

    # Access control is done inside the calls, rather than in here.
    $ret = [
        'ret' => 2,
        'status' => 'Failed'
    ];

    switch ($_REQUEST['type']) {
        case 'GET':
        {
            if ($id) {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'comment' => $u->getComment($id)
                ];
            } else {
                $comments = $u->listComments($ctx);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'comments' => $comments,
                    'context' => $ctx
                ];
            }

            break;
        }

        case 'POST':
        {
            $id = $u->addComment($groupid,
                $user1,
                $user2,
                $user3,
                $user4,
                $user5,
                $user6,
                $user7,
                $user8,
                $user9,
                $user10,
                $user11);

            if ($id) {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $id
                ];
            }

            break;
        }

        case 'PUT':
        {
            if ($id) {
                $rc = $u->editComment($id,
                    $user1,
                    $user2,
                    $user3,
                    $user4,
                    $user5,
                    $user6,
                    $user7,
                    $user8,
                    $user9,
                    $user10,
                    $user11);

                if ($rc) {
                    $ret = ['ret' => 0, 'status' => 'Success'];
                }
            }
            break;
        }

        case 'DELETE':
        {
            if ($id) {
                $rc = $u->deleteComment($id);

                if ($rc) {
                    $ret = ['ret' => 0, 'status' => 'Success'];
                }
            }
        }
    }

    return($ret);
}
