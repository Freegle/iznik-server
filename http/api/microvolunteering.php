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
                $groupid = (Utils::presint('groupid', $_REQUEST, 0));
                $list = Utils::presbool('list', $_REQUEST, FALSE);
                $ctx = Utils::presdef('context', $_REQUEST, NULL);

                $v = new MicroVolunteering($dbhr, $dbhm);

                if ($me && $me->isModerator() && $list) {
                    $items = $v->list($ctx, $groupid ? [ $groupid ] : $me->getModeratorships());
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'microvolunteerings' => $items,
                        'context' => $ctx
                    ];
                } else {
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
                }

                break;
            }

            case 'POST': {
                $v = new MicroVolunteering($dbhr, $dbhm);
                $msgid = (Utils::presint('msgid', $_REQUEST, 0));
                $msgcategory = Utils::presdef('msgcategory', $_REQUEST, NULL);
                $response = Utils::presdef('response', $_REQUEST, NULL);
                $comments = Utils::presdef('comments', $_REQUEST, NULL);
                $searchterm1 = (Utils::presint('searchterm1', $_REQUEST, 0));
                $searchterm2 = (Utils::presint('searchterm2', $_REQUEST, 0));
                $facebook = (Utils::presint('facebook', $_REQUEST, 0));
                $photoid = (Utils::presint('photoid', $_REQUEST, 0));
                $deg = (Utils::presint('deg', $_REQUEST, 0));

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
