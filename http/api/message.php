<?php
namespace Freegle\Iznik;

function message() {
    global $dbhr, $dbhm;

//    $dbhr->setErrorLog(TRUE);
//    $dbhm->setErrorLog(TRUE);

    $me = Session::whoAmI($dbhr, $dbhm);
    $myid = $me ? $me->getId() : NULL;

    $collection = Utils::presdef('collection', $_REQUEST, MessageCollection::APPROVED);
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));
    $id = (Utils::presint('id', $_REQUEST, NULL));
    $reason = Utils::presdef('reason', $_REQUEST, NULL);
    $action = Utils::presdef('action', $_REQUEST, NULL);
    $subject = Utils::presdef('subject', $_REQUEST, NULL);
    $body = Utils::presdef('body', $_REQUEST, NULL);
    $stdmsgid = (Utils::presint('stdmsgid', $_REQUEST, NULL));
    $messagehistory = array_key_exists('messagehistory', $_REQUEST) ? filter_var($_REQUEST['messagehistory'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $localonly = array_key_exists('localonly', $_REQUEST) ? filter_var($_REQUEST['localonly'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $userid = (Utils::presint('userid', $_REQUEST, NULL));
    $userid = $userid ? $userid : NULL;
    $summary = array_key_exists('summary', $_REQUEST) ? filter_var($_REQUEST['summary'], FILTER_VALIDATE_BOOLEAN) : FALSE;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $ischat = FALSE;

    switch ($_REQUEST['type']) {
        case 'GET':
        case 'PUT':
        case 'DELETE': {
            $m = new Message($dbhr, $dbhm, $id);

            if ((!$m->getID() && $collection != MessageCollection::DRAFT) || $m->getDeleted()) {
                $ret = ['ret' => 3, 'status' => 'Message does not exist'];
                $m = NULL;
            } else {
                switch ($collection) {
                    case MessageCollection::APPROVED:
                    case MessageCollection::DRAFT:
                        # No special checks for approved or draft - we could even be logged out.
                        break;
                    case MessageCollection::PENDING:
                    case MessageCollection::REJECTED:
                        if (!$me) {
                            $ret = ['ret' => 1, 'status' => 'Not logged in'];
                            $m = NULL;
                        } else {
                            $groups = $m->getGroups();
                            if (count($groups) == 0 || !$groupid || ($me && !$me->isModOrOwner($groups[0]))) {
                                $ret = ['ret' => 2, 'status' => 'Permission denied 1'];
                                $m = NULL;
                            }
                        }
                        break;
                    case MessageCollection::SPAM:
                        if (!$me) {
                            $ret = ['ret' => 1, 'status' => 'Not logged in'];
                            $m = NULL;
                        } else {
                            $groups = $m->getGroups();
                            if (count($groups) == 0 || !$groupid || !$me->isModOrOwner($groups[0])) {
                                $ret = ['ret' => 2, 'status' => 'Permission denied 2'];
                                $m = NULL;
                            }
                        }
                        break;
                    case MessageCollection::CHAT:
                        # We can see the original message for a chat if we're a mod.  This is used in chat
                        # message review when we want to show the source.
                        if (!$me || !$me->isModerator() || !$m->isChatByEmail()) {
                            $ret = ['ret' => 2, 'status' => 'Permission denied 3'];
                            $m = NULL;
                        } else {
                            $ischat = TRUE;
                        }
                        break;
                    default:
                        # If they don't say what they're doing properly, they can't do it.
                        $m = NULL;
                        $ret = [ 'ret' => 101, 'status' => 'Bad collection' ];
                        break;
                }
            }

            if ($m) {
                if ($_REQUEST['type'] == 'GET') {
                    $userlist = [];
                    $locationlist = [];
                    $atts = $m->getPublic($messagehistory, FALSE, FALSE, $userlist, $locationlist, $summary);
                    $mod = $me && $me->isModerator();

                    if ($mod && count($atts['groups']) == 0) {
                        $atts['message'] = $m->getPrivate('message');
                    }

                    $cansee = $summary || $m->canSee($atts) || $ischat;

                    # We want to return the groups info even if we can't see the message, so that we can tell them
                    # which group to join.
                    $ret = [
                        'ret' => 2,
                        'status' => 'Permission denied 4',
                        'groups' => []
                    ];

                    foreach ($atts['groups'] as &$group) {
                        # The groups info returned in the message is not enough - doesn't include settings, for
                        # example.
                        $g = Group::get($dbhr, $dbhm, $group['groupid']);
                        $ret['groups'][$group['groupid']] = $g->getPublic();
                    }

                    if ($cansee) {
                        $ret['ret'] = 0;
                        $ret['status'] = 'Success';
                        $ret['message'] = $atts;
                    }
                } else if ($_REQUEST['type'] == 'PUT') {
                    if ($collection == MessageCollection::DRAFT) {
                        # Draft messages are created by users, rather than parsed out from emails.  We might be
                        # creating one, or updating one.
                        $locationid = (Utils::presint('locationid', $_REQUEST, NULL));

                        $ret = [ 'ret' => 3, 'status' => 'Missing location - client error' ];

                        $email = Utils::presdef('email', $_REQUEST, NULL);
                        $uid = NULL;

                        if ($email) {
                            # We're queueing a draft so we need to save the user it.
                            $u = new User($dbhr, $dbhm);
                            $uid = $u->findByEmail($email);
                        }

                        if ($locationid) {
                            # We check the ID on the message object to handle the case where the client passes
                            # an ID which is not valid on the server.
                            if (!$m->getID()) {
                                $id = $m->createDraft($uid);

                                # Use the master to avoid any replication windows.
                                $m = new Message($dbhm, $dbhm, $id);

                                # Record the last message we created in our session.  We use this to give access to
                                # this message even if we're not logged in - for example when setting the FOP after
                                # message submission.
                                $_SESSION['lastmessage'] = $id;
                            } else {
                                # The message should be ours.
                                $sql = "SELECT * FROM messages_drafts WHERE msgid = ? AND session = ? OR (userid IS NOT NULL AND userid = ?);";
                                $drafts = $dbhr->preQuery($sql, [ $id, session_id(), $myid ]);
                                $m = NULL;
                                foreach ($drafts as $draft) {
                                    $m = new Message($dbhr, $dbhm, $draft['msgid']);

                                    # Update the arrival time so that it doesn't appear to be expired.
                                    $m->setPrivate('arrival', date("Y-m-d H:i:s", time()));
                                }

                                # The message is not in drafts.  This can happen if someone creates a draft on one
                                # device, then completes it on another, then goes back to the first and edits the
                                # draft into a new post.  In this case create a new draft message, which will
                                # override the one on the client.
                                if (!$m) {
                                    $m = new Message($dbhr, $dbhm);
                                    $id = $m->createDraft();
                                    $m = new Message($dbhm, $dbhm, $id);
                                    $_SESSION['lastmessage'] = $id;
                                }
                            }

                            if ($m) {
                                # Drafts have:
                                # - a locationid
                                # - a groupid (optional)
                                # - a type
                                # - an item
                                # - a number available
                                # - a subject constructed from the type, item and location.
                                # - a fromuser if known (we might not have logged in yet)
                                # - a textbody
                                # - zero or more attachments
                                if ($groupid) {
                                    $dbhm->preExec("UPDATE messages_drafts SET groupid = ? WHERE msgid = ?;", [$groupid, $m->getID()]);
                                }

                                $type = Utils::presdef('messagetype', $_REQUEST, NULL);

                                # Associated the item with the message.  Use the master to avoid replication windows.
                                $item = Utils::presdef('item', $_REQUEST, NULL);
                                $i = new Item($dbhm, $dbhm);
                                $itemid = $i->create($item);
                                $m->deleteItems();
                                $m->addItem($itemid);

                                $fromuser = $me ? $me->getId() : NULL;

                                if (!$fromuser) {
                                    # Creating a draft - use the supplied email.
                                    $fromuser = $uid;
                                }

                                $textbody = Utils::presdef('textbody', $_REQUEST, NULL);
                                $attachments = Utils::presdef('attachments', $_REQUEST, []);
                                $m->setPrivate('locationid', $locationid);
                                $m->setPrivate('type', $type);
                                $m->setPrivate('subject', $item);
                                $m->setPrivate('fromuser', $fromuser);
                                $m->setPrivate('textbody', $textbody);
                                $m->setPrivate('fromip', Utils::presdef('REMOTE_ADDR', $_SERVER, NULL));

                                $availablenow = Utils::presint('availablenow', $_REQUEST, 1);
                                $m->setPrivate('availableinitially', $availablenow);
                                $m->setPrivate('availablenow', $availablenow);

                                $m->replaceAttachments($attachments);

                                $ret = [
                                    'ret' => 0,
                                    'status' => 'Success',
                                    'id' => $id
                                ];
                            }
                        }
                    }
                } else if ($_REQUEST['type'] == 'DELETE') {
                    $role = $m->getRoleForMessage()[0];
                    if ($role != User::ROLE_OWNER && $role != User::ROLE_MODERATOR) {
                        $ret = ['ret' => 2, 'status' => 'Permission denied 5'];
                    } else {
                        $m->delete($reason, NULL, NULL, NULL, NULL, $localonly);
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    }
                }
            }
        }
        break;

        case 'PATCH': {
            $m = new Message($dbhr, $dbhm, $id);
            $ret = ['ret' => 3, 'status' => 'Message does not exist'];

            if ($m->getID()) {
                # See if we can modify.
                $canmod = $myid == $m->getFromuser();

                if (!$canmod) {
                    $role = $m->getRoleForMessage()[0];
                    $canmod = $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER;

                    if ($role == User::ROLE_OWNER && Utils::pres('partner', $_SESSION)) {
                        # We have acquired owner rights by virtue of being a partner.  Pretend to be that user for the
                        # rest of the call.
                        $_SESSION['id'] = $m->getFromuser();
                    }
                }

                if ($canmod) {
                    # Ignore the canedit flag here - the client will either show or not show the edit button on this
                    # basis but editing is part of the repost flow and therefore needs to work.
                    $subject = Utils::presdef('subject', $_REQUEST, NULL);
                    $msgtype = Utils::presdef('msgtype', $_REQUEST, NULL);
                    $item = Utils::presdef('item', $_REQUEST, NULL);
                    $location = Utils::presdef('location', $_REQUEST, NULL);
                    $lat = Utils::presfloat('lat', $_REQUEST, NULL);
                    $lng = Utils::presfloat('lng', $_REQUEST, NULL);
                    $textbody = Utils::presdef('textbody', $_REQUEST, NULL);
                    $fop = array_key_exists('FOP', $_REQUEST) ? $_REQUEST['FOP'] : NULL;
                    $availableinitially = Utils::presint('availableinitially', $_REQUEST, NULL);
                    $availablenow = Utils::presint('availablenow', $_REQUEST, NULL);
                    $attachments = array_key_exists('attachments', $_REQUEST) ? $_REQUEST['attachments'] : NULL;

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];

                    if ($availablenow !== NULL) {
                        $m->setPrivate('availablenow', $availablenow);
                    }

                    if ($availableinitially !== NULL) {
                        $m->setPrivate('availableinitially', $availableinitially);
                    }

                    if ($subject || $textbody || $msgtype || $item || $location || $attachments !== NULL || $lat || $lng) {
                        $partner = Utils::pres('partner', $_SESSION);

                        if ($partner) {
                            # Photos might have changed.
                            $m->deleteAllAttachments();
                            $textbody = $m->scrapePhotos($textbody);
                            $m->saveAttachments($id);

                            # Lat/lng might have changed
                            if ($lat || $lng) {
                                $m->setPrivate('lat', $lat);
                                $m->setPrivate('lng', $lng);
                            }
                        }

                        $rc = $m->edit($subject,
                          $textbody, 
                          $msgtype,
                          $item, 
                          $location, 
                          $attachments, 
                         TRUE, 
                         ($partner || ($me && $me->isApprovedMember($groupid))) ? $groupid : NULL);
                        
                        $ret = $rc ? $ret : ['ret' => 2, 'status' => 'Edit failed'];
                        
                        if ($rc) {
                            $ret = [
                                'ret' => 0,
                                'status' => 'Success'
                            ];
                        }
                    }

                    if ($fop !== NULL) {
                        $m->setFOP($fop);
                    }

                    if ($groupid) {
                        $dbhm->preExec("UPDATE messages_drafts SET groupid = ? WHERE msgid = ?;", [$groupid, $m->getID()]);
                    }
                } else {
                    $ret = ['ret' => 2,
                        'status' => 'Permission denied 6',
                        'fromuser' => $m->getFromuser()
                    ];
                }
            }
        }
        break;

        case 'POST': {
            $m = new Message($dbhr, $dbhm, $id);
            $ret = ['ret' => 2, 'status' => 'Permission denied 7 '];
            $role = $m && $id && $m->getId() == $id ? $m->getRoleForMessage()[0] : User::ROLE_NONMEMBER;

            if ($id && $m->getID() == $id) {
                # These actions don't require permission, but they do need to be logged in as they record the userid.
                if ($me) {
                    if ($action =='Love') {
                        $m->like($myid, Message::LIKE_LOVE);
                        $ret = [ 'ret' => 0, 'status' => 'Success' ];
                    } else if ($action == 'Unlove') {
                        $m->unlike($myid, Message::LIKE_LOVE);
                        $ret = [ 'ret' => 0, 'status' => 'Success' ];
                    } else if ($action == 'Laugh') {
                        $m->like($myid, Message::LIKE_LAUGH);
                        $ret = [ 'ret' => 0, 'status' => 'Success' ];
                    } else if ($action == 'Unlaugh') {
                        $m->unlike($myid, Message::LIKE_LAUGH);
                        $ret = [ 'ret' => 0, 'status' => 'Success' ];
                    } else if ($action == 'View') {
                        $m->like($myid, Message::LIKE_VIEW);
                        $ret = ['ret' => 0, 'status' => 'Success'];
                    }
                } else if ($action == 'View') {
                    // We don't currently record logged out views.
                    $ret = ['ret' => 0, 'status' => 'Success'];
                }
            }

            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                $ret = [ 'ret' => 0, 'status' => 'Success' ];

                switch ($action) {
                    case 'Delete':
                        # The delete call will handle any rejection on Yahoo if required.
                        $m->delete($reason, NULL, $subject, $body, $stdmsgid);
                        break;
                    case 'Reject':
                        # Ignore requests for messages which aren't pending.  Legitimate timing window when there
                        # are multiple mods.
                        if ($m->isPending($groupid)) {
                            $m->reject($groupid, $subject, $body, $stdmsgid);
                        }
                        break;
                    case 'Approve':
                        # Ignore requests for messages which aren't pending.  Legitimate timing window when there
                        # are multiple mods.
                        if ($m->isPending($groupid)) {
                            $m->approve($groupid, $subject, $body, $stdmsgid);
                        }
                        break;
                    case 'Reply':
                        $m->reply($groupid, $subject, $body, $stdmsgid);
                        break;
                    case 'Hold':
                        $m->hold();
                        break;
                    case 'Release':
                        $m->release();
                        break;
                    case 'NotSpam':
                        $m->notSpam();
                        $r = new MailRouter($dbhr, $dbhm);
                        $rc = $r->route($m, TRUE);
                        if ($rc == MailRouter::DROPPED || $rc == MailRouter::TO_SYSTEM) {
                            # We no longer want this message - for example because they're no longer a member.
                            $m->delete("Not spam and dropped/to system", $groupid);
                        }
                        break;
                    case 'Move':
                        $ret = $m->move($groupid);
                        break;
                    case 'Spam':
                        # Don't trust normal mods to categorise this correctly.  Often they will mark a message from
                        # a scammer trying to extort as spam, though the message itself is ok.  This tends to poison
                        # our filter.
                        if ($me->isAdminOrSupport()) {
                            $m->spam($groupid);
                        } else {
                            $m->delete("Categorised as spam by moderator");
                        }
                        break;
                    case 'JoinAndPost':
                        # This is the mainline case for someone posting a message.  We find the nearest group, sign
                        # them up if need be, and then post the message.  We do this without being logged in, because
                        # that reduces friction.  If there is abuse of this, then we will find other ways to block the
                        # abuse.
                        #
                        # The message we have in hand should be nobody else's
                        $ret = ['ret' => 3, 'status' => 'Not our message'];
                        $sql = "SELECT * FROM messages_drafts WHERE msgid = ? AND (session = ? OR (userid IS NOT NULL AND userid = ?));";
                        $drafts = $dbhr->preQuery($sql, [$id, session_id(), $myid]);
                        $newuser = NULL;
                        $pw = NULL;
                        $hitwindow = FALSE;
                        #error_log("$sql, $id, " . session_id() . ", $myid");

                        foreach ($drafts as $draft) {
                            $m = new Message($dbhr, $dbhm, $draft['msgid']);

                            if (!$draft['groupid']) {
                                # No group specified.  Find the group nearest the location.
                                $l = new Location($dbhr, $dbhm, $m->getPrivate('locationid'));
                                $ret = ['ret' => 4, 'status' => 'No nearby groups found'];
                                $nears = $l->groupsNear(200);
                            } else {
                                # A preferred group for this message.
                                $nears = [ $draft['groupid'] ];
                            }

                            // COVID Lockdown 2 - override to closest group.
                            $l = new Location($dbhr, $dbhm, $m->getPrivate('locationid'));
                            $nears2 = $l->groupsNear(200);
                            $nears = count($nears2) ? $nears2 : $nears;

                            // @codeCoverageIgnoreStart
                            if (defined('USER_GROUP_OVERRIDE') && !Utils::pres('ignoregroupoverride', $_REQUEST)) {
                                # We're in testing mode
                                $g = new Group($dbhr, $dbhm);
                                $nears = [ $g->findByShortName(USER_GROUP_OVERRIDE) ];
                            }
                            // @codeCoverageIgnoreEnd

                            if (count($nears) > 0) {
                                $groupid = $nears[0];

                                # Now we know which group we'd like to post on.  Make sure we have a user set up.
                                $email = Utils::presdef('email', $_REQUEST, NULL);
                                $u = User::get($dbhr, $dbhm);
                                $uid = $u->findByEmail($email);

                                $ret = ['ret' => 5, 'status' => 'Failed to create user or email'];

                                if (!$uid) {
                                    # We don't yet know this user.  Create them.
                                    $name = substr($email, 0, strpos($email, '@'));
                                    $newuser = $u->create(null, null, $name, 'Created to allow post');

                                    # Create a password and mail it to them.  Also log them in and return it.  This
                                    # avoids us having to ask the user for a password, though they can change it if
                                    # they like.  Less friction.
                                    $pw = $u->inventPassword();
                                    $u->addLogin(User::LOGIN_NATIVE, $newuser, $pw);
                                    $eid = $u->addEmail($email, 1);

                                    if (!$eid) {
                                        # There's a timing window where a parallel request could have added this
                                        # email.  Check.
                                        $uid2 = $u->findByEmail($email);

                                        if ($uid2) {
                                            # That has happened.  Delete the user we created and use the other.
                                            $u->delete();
                                            $newuser = null;
                                            $pw = null;
                                            $hitwindow = true;

                                            $u = User::get($dbhr, $dbhm, $uid2);
                                            $eid = $u->getIdForEmail($email)['id'];

                                            if ($u->getEmailPreferred() != $email) {
                                                # The email specified is the one they currently want to use - make sure it's
                                                $u->addEmail($email, 1, true);
                                            }
                                        }
                                    } else {
                                        $u->login($pw);
                                        $u->welcome($email, $pw);
                                    }
                                } else if ($me && $me->getId() != $uid) {
                                    # We know the user, but it's not the one we're logged in as.  It's most likely
                                    # that the user is just confused about multiple email addresses.  We will reject
                                    # the message - the client is supposed to detect this case earlier on.
                                    $ret = ['ret' => 6, 'status' => 'That email address is in use for a different user.'];
                                } else {
                                    $u = User::get($dbhr, $dbhm, $uid);
                                    $eid = $u->getIdForEmail($email)['id'];

                                    if ($u->getEmailPreferred() != $email) {
                                        # The email specified is the one they currently want to use - make sure it's
                                        # in there.
                                        $u->addEmail($email, 1, TRUE);
                                    }
                                }

                                if ($u->getId() && $eid) {
                                    # Now we have a user and an email.  We need to make sure they're a member of the
                                    # group in question.
                                    $g = Group::get($dbhr, $dbhm, $groupid);
                                    $fromemail = NULL;
                                    $cont = TRUE;

                                    # Check the message for worry words.
                                    $w = new WorryWords($dbhr, $dbhm);
                                    $worry = $w->checkMessage($m->getID(), $m->getFromuser(), $m->getSubject(), $m->getTextbody());

                                    # Assume this post is moderated unless we decide otherwise below.
                                    if (!$u->isApprovedMember($groupid)) {
                                        # We're not yet a member.  Join the group.
                                        $addworked = $u->addMembership($groupid);

                                        # This is now a member, and we always moderate posts from new members,
                                        # so this goes to pending.
                                        $postcoll = MessageCollection::PENDING;

                                        if ($addworked === FALSE) {
                                            # We couldn't join - we're banned.  Suppress the message below.
                                            $cont = FALSE;

                                            # Pretend it worked, if we suppressed a banned message.
                                            $ret = ['ret' => 0, 'status' => 'Success', 'groupid' => $groupid, 'id' => $id];
                                        }
                                    } else if ($me && $me->getMembershipAtt($groupid, 'ourPostingStatus') == Group::POSTING_PROHIBITED) {
                                        # We're not allowed to post.
                                        $cont = FALSE;
                                        $ret = ['ret' => 8, 'status' => 'Not allowed to post on this group'];
                                    } else {
                                        # They're already a member, so we might be able to put this straight
                                        # to approved.
                                        #
                                        # The entire group might be moderated, or the member might be, in which
                                        # case the message goes to pending, otherwise approved.
                                        #
                                        # Worrying messages always go to Pending.
                                        if ($g->getPrivate('overridemoderation') === Group::OVERRIDE_MODERATION_ALL) {
                                            $postcoll = MessageCollection::PENDING;
                                        } else {
                                            $postcoll = ($worry || $g->getSetting('moderated', 0) || $g->getSetting('close', 0)) ? MessageCollection::PENDING : $u->postToCollection($groupid);
                                        }
                                    }

                                    # Check if it's spam
                                    $s = new Spam($dbhr, $dbhm);
                                    list ($rc, $reason) = $s->checkMessage($m);

                                    if ($rc) {
                                        # It is.  Put in the spam collection.
                                        $postcoll = MessageCollection::SPAM;
                                    }

                                    # We want the message to come from one of our emails rather than theirs, so
                                    # that replies come back to us and privacy is maintained.
                                    $fromemail = $u->inventEmail();

                                    # Make sure this email is attached to the user so that we don't invent
                                    # another next time.
                                    $u->addEmail($fromemail, 0, FALSE);

                                    $m->constructSubject($groupid);

                                    if ($cont) {
                                        if ($fromemail) {
                                            $dbhm->preExec("INSERT IGNORE INTO messages_groups (msgid, groupid, collection,arrival, msgtype) VALUES (?,?,?,NOW(),?);", [
                                                $draft['msgid'],
                                                $groupid,
                                                $postcoll,
                                                $m->getType()
                                            ]);

                                            $ret = ['ret' => 7, 'status' => 'Failed to submit'];

                                            if ($m->submit($u, $fromemail, $groupid)) {
                                                # We sent it.
                                                $ret = ['ret' => 0, 'status' => 'Success', 'groupid' => $groupid];

                                                if ($postcoll == MessageCollection::APPROVED) {
                                                    # We index now; for pending messages we index when they are approved.
                                                    $m->addToSpatialIndex();
                                                    $m->index();
                                                }
                                            }
                                        }
                                    }

                                    # This user has been active recently.
                                    $dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = " . $u->getId() . ";");
                                }
                            }
                        }

                        $ret['newuser'] = $newuser;
                        $ret['newpassword'] = $pw;
                        $ret['hitwindow'] = $hitwindow;

                        break;
                    case 'BackToDraft':
                    case 'RejectToDraft':
                        # This is a message which has been rejected or reposted, which we are now going to edit.
                        $ret = ['ret' => 3, 'status' => 'Message does not exist'];
                        $sql = "SELECT * FROM messages WHERE id = ?;";
                        $msgs = $dbhr->preQuery($sql, [ $id ]);

                        foreach ($msgs as $msg) {
                            $m = new Message($dbhr, $dbhm, $id);
                            $ret = ['ret' => 4, 'status' => 'Failed to edit message'];

                            $role = $m->getRoleForMessage()[0];

                            if ($role == User::ROLE_MODERATOR || $role = User::ROLE_OWNER) {
                                $rc = $m->backToDraft();

                                if ($rc) {
                                    $ret = ['ret' => 0, 'status' => 'Success', 'messagetype' => $m->getType() ];
                                }
                            }
                        }
                        break;
                    case 'RevertEdits':
                        $editid = (Utils::presint('editid', $_REQUEST, 0));
                        $role = $m->getRoleForMessage()[0];

                        if ($role === User::ROLE_OWNER || $role === User::ROLE_MODERATOR) {
                            $m->revertEdit($editid);
                            $ret = ['ret' => 0, 'status' => 'Success' ];
                        }
                        break;
                    case 'ApproveEdits':
                        $editid = (Utils::presint('editid', $_REQUEST, 0));
                        $role = $m->getRoleForMessage()[0];

                        if ($role === User::ROLE_OWNER || $role === User::ROLE_MODERATOR) {
                            $m->approveEdit($editid);
                            $ret = ['ret' => 0, 'status' => 'Success' ];
                        }
                        break;
                    case 'PartnerConsent':
                        $partner = Utils::presdef('partner', $_REQUEST, NULL);
                        $ret = ['ret' => 5, 'status' => 'Invalid parameters' ];

                        $role = $m->getRoleForMessage()[0];

                        if ($partner && ($role === User::ROLE_OWNER || $role === User::ROLE_MODERATOR)) {
                            if ($m->partnerConsent($partner)) {
                                $ret = ['ret' => 0, 'status' => 'Success' ];
                            }
                        }
                        break;
                }
            }

            if ($id && $id == $m->getId()) {
                # Other actions which we can do on our own messages.
                $canmod = $myid == $m->getFromuser();

                if (!$canmod) {
                    $role = $m->getRoleForMessage()[0];
                    $canmod = $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER;

                    if ($role == User::ROLE_OWNER && Utils::pres('partner', $_SESSION)) {
                        # We have acquired owner rights by virtue of being a partner.  Pretend to be that user for the
                        # rest of the call.
                        $_SESSION['id'] = $m->getFromuser();
                        $myid = $m->getFromuser();
                    }
                }

                if ($canmod) {
                    if ($userid > 0) {
                        $r = new ChatRoom($dbhr, $dbhm);
                        $rid = $r->createConversation($myid, $userid);
                        $cm = new ChatMessage($dbhr, $dbhm);
                    }

                    switch ($action) {
                        case 'Promise':
                            if ($userid) {
                                # Userid is optional.
                                $m->promise($userid);
                                list ($mid, $banned) = $cm->create($rid, $myid, NULL, ChatMessage::TYPE_PROMISED, $id);
                                $ret = ['ret' => 0, 'status' => 'Success', 'id' => $mid];
                            } else {
                                $m->promise($m->getFromuser());
                                $ret = ['ret' => 0, 'status' => 'Success'];
                            }
                            break;
                        case 'Renege':
                            # Userid is optional - TN doesn't use it.
                            if ($userid > 0) {
                                $m->renege($userid);
                                list ($mid, $banned) = $cm->create($rid, $myid, NULL, ChatMessage::TYPE_RENEGED, $id);
                                $ret = ['ret' => 0, 'status' => 'Success', 'id' => $mid];
                            } else {
                                $m->renege($m->getFromuser());
                                $ret = ['ret' => 0, 'status' => 'Success'];
                            }

                            break;
                        case 'OutcomeIntended':
                            # Ignore duplicate attempts by user to supply an outcome.
                            if (!$m->hasOutcome()) {
                                $outcome = Utils::presdef('outcome', $_REQUEST, NULL);
                                $m->intendedOutcome($outcome);
                            }
                            $ret = ['ret' => 0, 'status' => 'Success'];
                            break;
                        case 'AddBy':
                            $count = Utils::presint('count', $_REQUEST, NULL);

                            if ($count !== NULL) {
                                $m->addBy($userid, $count);
                                $ret = ['ret' => 0, 'status' => 'Success'];
                            }
                            break;
                        case 'RemoveBy':
                            $m->removeBy($userid);
                            $ret = ['ret' => 0, 'status' => 'Success'];
                            break;
                        case 'Outcome':
                            # Ignore duplicate attempts by user to supply an outcome.
                            if (!$m->hasOutcome()) {
                                $outcome = Utils::presdef('outcome', $_REQUEST, NULL);
                                $h = Utils::presdef('happiness', $_REQUEST, NULL);
                                $happiness = NULL;

                                switch ($h) {
                                    case User::HAPPY:
                                    case User::FINE:
                                    case User::UNHAPPY:
                                        $happiness = $h;
                                        break;
                                }

                                $comment = Utils::presdef('comment', $_REQUEST, NULL);

                                $ret = ['ret' => 1, 'status' => 'Odd action'];

                                switch ($outcome) {
                                    case Message::OUTCOME_TAKEN: {
                                        if ($m->getType() == Message::TYPE_OFFER) {
                                            $m->mark($outcome, $comment, $happiness, $userid);
                                            $ret = ['ret' => 0, 'status' => 'Success'];
                                        };
                                        break;
                                    }
                                    case Message::OUTCOME_RECEIVED: {
                                        if ($m->getType() == Message::TYPE_WANTED) {
                                            $m->mark($outcome, $comment, $happiness, $userid);
                                            $ret = ['ret' => 0, 'status' => 'Success'];
                                        };
                                        break;
                                    }
                                    case Message::OUTCOME_WITHDRAWN: {
                                        $m->withdraw($comment, $happiness, $userid);
                                        $ret = ['ret' => 0, 'status' => 'Success'];

                                        # The message might still be pending.
                                        $groups = $m->getGroups(FALSE, TRUE);

                                        foreach ($groups as $gid) {
                                            if ($m->isPending($gid)) {
                                                $g = Group::get($dbhr, $dbhm, $gid);

                                                # If a message is withdrawn while it's pending we might as well delete it.
                                                $m->delete("Withdrawn pending");
                                                $ret['deleted'] = TRUE;
                                            } else {
                                                $m->withdraw($comment, $happiness, $userid);
                                            }
                                        }

                                        break;
                                    }
                                }
                            } else {
                                $ret = ['ret' => 0, 'status' => 'Success'];
                            }
                            break;
                    }
                }
            }
        }
    }

    return($ret);
}
