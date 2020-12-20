<?php
namespace Freegle\Iznik;

function comment() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $id = (Utils::presint('id', $_REQUEST, NULL));
    $ctx = Utils::presdef('context', $_REQUEST, NULL);
    $userid = (Utils::presint('userid', $_REQUEST, NULL));
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));
    $user1 = $user2 = $user3 = $user4 = $user5 = $user6 = $user7 = $user8 = $user9 = $user10 = $user11 = NULL;

    for ($i = 1; $i <= 11; $i++) {
        $user1 = Utils::presdef("user1", $_REQUEST, NULL);
        $user2 = Utils::presdef("user2", $_REQUEST, NULL);
        $user3 = Utils::presdef("user3", $_REQUEST, NULL);
        $user4 = Utils::presdef("user4", $_REQUEST, NULL);
        $user5 = Utils::presdef("user5", $_REQUEST, NULL);
        $user6 = Utils::presdef("user6", $_REQUEST, NULL);
        $user7 = Utils::presdef("user7", $_REQUEST, NULL);
        $user8 = Utils::presdef("user8", $_REQUEST, NULL);
        $user9 = Utils::presdef("user9", $_REQUEST, NULL);
        $user10 = Utils::presdef("user10", $_REQUEST, NULL);
        $user11 = Utils::presdef("user11", $_REQUEST, NULL);
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
