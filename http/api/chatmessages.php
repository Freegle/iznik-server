<?php
function chatmessages() {
    global $dbhr, $dbhm;

    $roomid = intval(presdef('roomid', $_REQUEST, NULL));
    $message = presdef('message', $_REQUEST, NULL);
    $refmsgid = presdef('refmsgid', $_REQUEST, NULL);
    $refchatid = presdef('refchatid', $_REQUEST, NULL);
    $imageid = presdef('imageid', $_REQUEST, NULL);
    $addressid = presdef('addressid', $_REQUEST, NULL);
    $reportreason = presdef('reportreason', $_REQUEST, NULL);
    $refmsgsummary = array_key_exists('refmsgsummary', $_REQUEST) ? filter_var($_REQUEST['refmsgsummary'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $ctx = presdef('context', $_REQUEST, NULL);
    $ctx = (isset($ctx) && $ctx !== 'undefined') ? $ctx : NULL;
    $limit = array_key_exists('limit', $_REQUEST) ? intval($_REQUEST['limit']) : 100;

    $r = new ChatRoom($dbhr, $dbhm, $roomid);
    $id = intval(presdef('id', $_REQUEST, NULL));
    $m = new ChatMessage($dbhr, $dbhm, $id);
    $me = whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET':
        {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $ret = ['ret' => 2, 'status' => "$roomid Not visible to you"];
                if ($roomid && $r->canSee($me->getId())) {
                    if ($id) {
                        $userlist = NULL;

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'chatmessage' => $m->getPublic(FALSE, $userlist)
                        ];

                        # We don't want to show someone whether their messages are held for review.
                        unset($ret['chatmessage']['reviewrequired']);
                        unset($ret['chatmessage']['reviewedby']);
                        unset($ret['chatmessage']['reviewrejected']);

                        $u = User::get($dbhr, $dbhm, $ret['chatmessage']['userid']);
                        $ret['chatmessage']['user'] = $u->getPublic(NULL, FALSE);
                    } else {
                        list($msgs, $users) = $r->getMessages($limit, NULL, $ctx, $refmsgsummary);
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'chatmessages' => $msgs,
                            'chatusers' => $users,
                            'context' => $ctx
                        ];
                    }
                } else if ($me->isModerator()) {
                    # See if we have any messages for review.
                    $r = new ChatRoom($dbhr, $dbhm);
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'chatmessages' => $r->getMessagesForReview($me, $ctx),
                        'chatreports' => []
                    ];

                    $ret['context'] = $ctx;
                }
            }
            break;
        }

        case 'POST':
            {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];

                if ($me) {
                    $ret = ['ret' => 2, 'status' => 'Not visible to you'];
                    $action = presdef('action', $_REQUEST, NULL);

                    if ($action == ChatMessage::ACTION_APPROVE && $id) {
                        $m->approve($id);
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    } else if ($action == ChatMessage::ACTION_APPROVE_ALL_FUTURE && $id) {
                        $m->approve($id);
                        $u = User::get($dbhr, $dbhm, $m->getPrivate('userid'));
                        $u->setPrivate('chatmodstatus', User::CHAT_MODSTATUS_UNMODERATED);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    } else if ($action == ChatMessage::ACTION_REJECT && $id) {
                        $m->reject($id);
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    } else if ($action == ChatMessage::ACTION_HOLD && $id) {
                        $m->hold($id);
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    } else if ($action == ChatMessage::ACTION_RELEASE && $id) {
                        $m->release($id);
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    } else if (($message || $imageid || $addressid) && $roomid && $r->canSee($me->getId())) {
                        if ($refmsgid) {
                            $type = ChatMessage::TYPE_INTERESTED;
                        } else if ($refchatid) {
                            $type = ChatMessage::TYPE_REPORTEDUSER;
                        } else if ($imageid) {
                            $type = ChatMessage::TYPE_IMAGE;
                        } else if ($addressid) {
                            $type = ChatMessage::TYPE_ADDRESS;
                            $message = $addressid;
                        } else {
                            $type = pres('modnote', $_REQUEST) ? ChatMessage::TYPE_MODMAIL : ChatMessage::TYPE_DEFAULT;
                        }

                        $id = $m->checkDup($roomid,
                            $me->getId(),
                            $message,
                            $type,
                            $refmsgid,
                            TRUE,
                            NULL,
                            $reportreason,
                            $refchatid,
                            $imageid);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'id' => $id
                        ];

                        if (!$id) {
                            list ($id, $banned) = $m->create($roomid,
                                $me->getId(),
                                $message,
                                $type,
                                $refmsgid,
                                TRUE,
                                NULL,
                                $reportreason,
                                $refchatid,
                                $imageid);

                            $ret = $banned ? ['ret' => 4, 'status' => 'Message create blocked'] : ['ret' => 3, 'status' => 'Message create failed'];

                            if ($id) {
                                if ($refmsgid) {
                                    # If the refmsg has completed or is promised to someone else, then no need to
                                    # email notify the recipient.
                                    $refm = new Message($dbhr, $dbhm, $refmsgid);
                                    $promisedto = $refm->promisedTo();

                                    if ($refm->hasOutcome() || ($promisedto && $promisedto != $me->getId())) {
                                        $r->mailedLastForUser($refm->getFromuser());
                                    }
                                }

                                $ret = [
                                    'ret' => 0,
                                    'status' => 'Success',
                                    'id' => $id
                                ];
                            }
                        }
                    }
                }
            }
            break;

        case 'PATCH':
            {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];

                if ($me) {
                    $ret = ['ret' => 2, 'status' => "$roomid Not visible to you"];
                    if ($id && $roomid && $m->getPrivate('userid') == $me->getId()) {
                        foreach (['replyexpected'] as $attr) {
                            if (array_key_exists($attr, $_REQUEST)) {
                                $m->setPrivate($attr, $_REQUEST[$attr]);
                            }
                        }

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    }
                }
            }
            break;
    }

    return($ret);
}
