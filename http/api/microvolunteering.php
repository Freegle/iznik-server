<?php
namespace Freegle\Iznik;

function microvolunteering() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);
    $myid = $me ? $me->getId() : NULL;

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $v = new MicroVolunteering($dbhr, $dbhm);
                $groupid = intval(Utils::presdef('groupid', $_REQUEST, 0));

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'microvolunteering' => $v->challenge($myid, $groupid)
                ];
                break;
            }

            case 'POST': {
                $v = new MicroVolunteering($dbhr, $dbhm);
                $msgid = intval(Utils::presdef('msgid', $_REQUEST, 0));
                $response = Utils::presdef('response', $_REQUEST, NULL);
                $comments = Utils::presdef('comments', $_REQUEST, NULL);

                $ret = [ 'ret' => 3, 'status' => 'Invalid parameters' ];

                if ($msgid && $response) {
                    $v->response($myid, $msgid, $response, $comments);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
            }
        }
    }

    return($ret);
}
