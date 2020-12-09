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
                $types = Utils::presdef('types', $_REQUEST, [
                    MicroVolunteering::CHALLENGE_SEARCH_TERM,
                    MicroVolunteering::CHALLENGE_CHECK_MESSAGE,
                    MicroVolunteering::CHALLENGE_PHOTO_ROTATE
                ]);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'microvolunteering' => $v->challenge($myid, $groupid, $types)
                ];
                break;
            }

            case 'POST': {
                $v = new MicroVolunteering($dbhr, $dbhm);
                $msgid = intval(Utils::presdef('msgid', $_REQUEST, 0));
                $msgcategory = Utils::presdef('msgcategory', $_REQUEST, NULL);
                $response = Utils::presdef('response', $_REQUEST, NULL);
                $comments = Utils::presdef('comments', $_REQUEST, NULL);
                $searchterm1 = intval(Utils::presdef('searchterm1', $_REQUEST, 0));
                $searchterm2 = intval(Utils::presdef('searchterm2', $_REQUEST, 0));
                $facebook = intval(Utils::presdef('facebook', $_REQUEST, 0));
                $photoid = intval(Utils::presdef('photoid', $_REQUEST, 0));
                $deg = intval(Utils::presdef('deg', $_REQUEST, 0));

                $ret = [ 'ret' => 3, 'status' => 'Invalid parameters' ];

                if ($msgid && $response) {
                    $v->responseCheckMessage($myid, $msgid, $response, $msgcategory, $comments);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($searchterm1 && $searchterm2) {
                    $v->responseItems($myid, $searchterm1, $searchterm2);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($facebook) {
                    $v->responseFacebook($myid, $facebook, $response);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($photoid) {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'rotated' => $v->responsePhotoRotate($myid, $photoid, $response, $deg)
                    ];
                }
            }
        }
    }

    return($ret);
}
