<?php
namespace Freegle\Iznik;

function chatrooms() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);
    $myid = $me ? $me->getId() : $me;

    $id = (Utils::presint('id', $_REQUEST, NULL));
    $userid = (Utils::presint('userid', $_REQUEST, NULL));
    $r = new ChatRoom($dbhr, $dbhm, $id);
    $chattypes = Utils::presdef('chattypes', $_REQUEST, [ ChatRoom::TYPE_USER2USER ]);
    $chattype = Utils::presdef('chattype', $_REQUEST, ChatRoom::TYPE_USER2USER);
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));
    $search = Utils::presdef('search', $_REQUEST, NULL);
    $summary = array_key_exists('summary', $_REQUEST) ? filter_var($_REQUEST['summary'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $count = array_key_exists('count', $_REQUEST) ? filter_var($_REQUEST['count'], FILTER_VALIDATE_BOOLEAN) : FALSE;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($count) {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];
                if ($me) {
                    $ret = ['ret' => 0, 'status' => 'Success', 'count' => $r->countAllUnseenForUser($myid, $chattypes) ];
                }
            } else  if ($id) {
                $ret = [ 'ret' => 0, 'status' => 'Success' ];
                $ret['chatroom'] = NULL;

                if ($r->canSee($myid)) {
                    $ret['chatroom'] = $r->getPublic();
                    $ret['chatroom']['unseen'] = $r->unseenCountForUser($myid);
                    $ret['chatroom']['lastmsgseen'] = $r->lastSeenForUser($myid);

                    if (!Session::modtools() && (!Utils::pres('latestmessage', $ret['chatroom']) ||
                        time() - strtotime($ret['chatroom']['latestmessage']) > 31 * 24 * 3600)) {
                        // This is an old chat which we have decided to fetch. Update latestmessage to make
                        // sure it will subsequently appear in listForUser
                        $r->ensureAppearInList($id);
                    }
                }
            } else {
                $ctx = NULL;
                $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

                if ($me) {
                    $ret = [ 'ret' => 0, 'status' => 'Success' ];

                    $rooms = $r->listForUser(Session::modtools(), $myid, $chattypes, $search, NULL, ChatRoom::ACTIVELIM);
                    $ret['chatrooms'] = [];

                    if ($rooms) {
                        # Get all the attributes we need in a single query for performance reasons.
                        $ret['chatrooms'] = $r->fetchRooms($rooms, $myid, $summary);
                    }
                }
            }
            break;
        }

        case 'PUT': {
            # Create a conversation.
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                switch ($chattype) {
                    case ChatRoom::TYPE_USER2USER:
                        if ($userid) {
                            if ($myid != $userid) {
                                $id = $r->createConversation($myid, $userid);

                                if ($id) {
                                    $r = new ChatRoom($dbhr, $dbhm, $id);

                                    # Ensure the chat isn't blocked.  Check the user to make sure we don't insert a mod into
                                    # the chat.
                                    if ($myid == $r->getPrivate('user1') || $myid == $r->getPrivate('user2')) {
                                        $r->updateRoster($myid, NULL);
                                    }
                                }
                            }
                        }
                        break;
                    case ChatRoom::TYPE_USER2MOD:
                        # On FD this must use the logged in user.  On MT we would be creating a chat to
                        # a different user.
                        $id = $r->createUser2Mod(Session::modtools() ? ($userid ? $userid : $myid) : $myid, $groupid);

                        if (Session::modtools()) {
                            # Ensure the chat isn't blocked in case we (as a mod) closed it before.
                            $r->updateRoster($myid, NULL);
                        }
                        break;
                }

                $ret = ['ret' => 3, 'status' => 'Create failed'];

                if ($id) {
                    $ret = ['ret' => 0, 'status' => 'Success', 'id' => $id];
                }
            }
            break;
        }

        case 'POST': {
            # Update our presence and get the current roster.
            $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];
            $action = Utils::presdef('action', $_REQUEST, NULL);

            if ($me) {
                if ($action == 'AllSeen') {
                    $r->upToDateAll($myid);
                    $ret = ['ret' => 0, 'status' => 'Success'];
                } else if ($action == 'Nudge') {
                    $id = $r->nudge();
                    $ret = ['ret' => 0, 'status' => 'Success', 'id' => $id];
                } else if ($id) {
                    # Single roster update.
                    $ret = ['ret' => 2, 'status' => "$id Not visible to you"];

                    # We should only update the roster for a chat we can legitimately be a member of.  We have had
                    # client bugs where the client updates a User2User chat for a mod, thereby inserting them into the
                    # chat.
                    $chattype = $r->getPrivate('chattype');

                    if ($r->canSee($myid, FALSE) &&
                        ($chattype == ChatRoom::TYPE_USER2MOD ||
                            $chattype == ChatRoom::TYPE_GROUP ||
                            $chattype == ChatRoom::TYPE_MOD2MOD ||
                            ($chattype == ChatRoom::TYPE_USER2USER &&
                                ($myid == $r->getPrivate('user1') || $myid == $r->getPrivate('user2')))
                        )
                    ) {
                        $ret = ['ret' => 0, 'status' => 'Success'];
                        $lastmsgseen = Utils::presint('lastmsgseen', $_REQUEST, NULL);
                        $status = Utils::presdef('status', $_REQUEST, ChatRoom::STATUS_ONLINE);
                        $r->updateRoster($myid, $lastmsgseen, $status);

                        $ret['roster'] = $r->getRoster();
                        $ret['unseen'] = $r->unseenCountForUser($myid);
                        $ret['nolog'] = TRUE;
                    }
                }
            }
        }
    }

    return($ret);
}
