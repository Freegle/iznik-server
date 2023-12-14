<?php
namespace Freegle\Iznik;

function newsfeed() {
    global $dbhr, $dbhm;

    $myid = Session::whoAmId($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $id = (Utils::presint('id', $_REQUEST, NULL));
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                if ($id) {
                    # Use the master as we fetch after posting and could otherwise miss it due to replication delay.
                    $n = new Newsfeed($dbhm, $dbhm, $id);
                    $lovelist = array_key_exists('lovelist', $_REQUEST) ? filter_var($_REQUEST['lovelist'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                    $unfollowed = array_key_exists('unfollowed', $_REQUEST) ? filter_var($_REQUEST['unfollowed'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                    $allreplies = array_key_exists('allreplies', $_REQUEST) ? filter_var($_REQUEST['allreplies'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                    $entry = $n->getPublic($lovelist, $unfollowed, $allreplies);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'newsfeed' => $entry
                    ];
                } else {
                    $n = new Newsfeed($dbhr, $dbhm);
                    $count = array_key_exists('count', $_REQUEST) ? filter_var($_REQUEST['count'], FILTER_VALIDATE_BOOLEAN) : FALSE;

                    if ($count) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'unseencount' => $n->getUnseen($myid)
                        ];
                    } else {
                        $ctx = Utils::presdef('context', $_REQUEST, NULL);
                        $dist = array_key_exists('distance', $_REQUEST) ? $_REQUEST['distance'] : Newsfeed::DISTANCE;

                        if ($dist == 'nearby') {
                            if ($ctx && array_key_exists('distance', $ctx)) {
                                # We have a distance from the last request.
                                $dist = intval($ctx['distance']);
                            } else {
                                $dist = $n->getNearbyDistance($myid);
                            }
                        }

                        $dist = intval($dist);

                        $types = Utils::presdef('types', $_REQUEST, NULL);

                        list ($users, $items) = $n->getFeed($myid, $dist, $types, $ctx);

                        $ret = [
                            'ret' => 0,
                            'context' => $ctx,
                            'status' => 'Success',
                            'newsfeed' => $items,
                            'users' => $users
                        ];
                    }
                }
                break;
            }

            case 'POST': {
                $n = new Newsfeed($dbhr, $dbhm, $id);
                $message = Utils::presdef('message', $_REQUEST, NULL);
                $replyto = Utils::pres('replyto', $_REQUEST) ? intval($_REQUEST['replyto']) : NULL;
                $action = Utils::presdef('action', $_REQUEST, NULL);
                $reason = Utils::presdef('reason', $_REQUEST, NULL);
                $imageid = (Utils::presint('imageid', $_REQUEST, NULL));

                if ($action == 'Love') {
                    $n->like();

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'Unlove') {
                    $n->unlike();

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'Report') {
                    $n->report($reason);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'Seen') {
                    $n->seen($myid);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'ReferToWanted') {
                    $n->refer(Newsfeed::TYPE_REFER_TO_WANTED);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'ReferToOffer') {
                    $n->refer(Newsfeed::TYPE_REFER_TO_OFFER);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'ReferToTaken') {
                    $n->refer(Newsfeed::TYPE_REFER_TO_TAKEN);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'ReferToReceived') {
                    $n->refer(Newsfeed::TYPE_REFER_TO_RECEIVED);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'Follow') {
                    $n->follow($myid, $id);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'Unhide') {
                    $ret = [
                        'ret' => 2,
                        'status' => 'Permission denied'
                    ];

                    $me = Session::whoAmI($dbhr, $dbhm);

                    if ($me->isAdminOrSupport()) {
                        $n->unhide($id);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    }
                } else if ($action == 'Unfollow') {
                    $n->unfollow($myid, $id);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                } else if ($action == 'AttachToThread') {
                    $ret = [
                        'ret' => 2,
                        'status' => 'Permission denied'
                    ];

                    $me = Session::whoAmI($dbhr, $dbhm);

                    if ($me->isModerator()) {
                        $n->setPrivate('replyto', (Utils::presint('attachto', $_REQUEST, 0)));

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    }
                } else if ($action == 'ConvertToStory') {
                    $ret = [
                        'ret' => 2,
                        'status' => 'Permission denied'
                    ];
                    $me = Session::whoAmI($dbhr, $dbhm);
                    if ($me && $me->isAdmin()) {
                        $retid = $n->convertToStory($id);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'id' => $retid
                        ];
                    }
                } else {
                    $s = new Spam($dbhr, $dbhm);
                    $spammers = $s->getSpammerByUserid($myid);
                    if (!$spammers) {
                        $id = $n->create(Newsfeed::TYPE_MESSAGE, $myid, $message, $imageid, NULL, $replyto, NULL);
                    }

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'id' => $id
                    ];
                }
                break;
            }

            case 'PATCH': {
                $n = new Newsfeed($dbhr, $dbhm, $id);
                # Can mod own posts or if mod.
                $message = Utils::presdef('message', $_REQUEST, NULL);

                $ret = [
                    'ret' => 2,
                    'status' => 'Permission denied'
                ];

                $me = Session::whoAmI($dbhr, $dbhm);

                if ($me->isModerator() || ($myid == $n->getPrivate('userid'))) {
                    $n->setPrivate('message', $message);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
                break;
            }

            case 'DELETE': {
                $n = new Newsfeed($dbhr, $dbhm, $id);
                $id = (Utils::presint('id', $_REQUEST, NULL));

                $ret = [
                    'ret' => 2,
                    'status' => 'Permission denied'
                ];

                # Can delete own posts or if mod.
                $me = Session::whoAmI($dbhr, $dbhm);

                if ($me->isModerator() || ($myid == $n->getPrivate('userid'))) {
                    $n->delete($myid, $id);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
                break;
            }
        }
    }

    return($ret);
}
