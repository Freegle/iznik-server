<?php
namespace Freegle\Iznik;

function memberships() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $userid = (Utils::presint('userid', $_REQUEST, NULL));
    $happinessid = (Utils::presint('happinessid', $_REQUEST, NULL));

    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));
    $g = Group::get($dbhr, $dbhm, $groupid);

    $role = Utils::presdef('role', $_REQUEST, User::ROLE_MEMBER);
    $email = Utils::presdef('email', $_REQUEST, NULL);
    $limit = Utils::presint('limit', $_REQUEST, 5);
    $search = Utils::presdef('search', $_REQUEST, NULL);
    $ctx = Utils::presdef('context', $_REQUEST, NULL);
    $settings = Utils::presdef('settings', $_REQUEST, NULL);
    $emailfrequency = Utils::presint('emailfrequency', $_REQUEST, NULL);
    $eventsallowed = Utils::presint('eventsallowed', $_REQUEST, NULL);
    $volunteeringallowed = Utils::presint('volunteeringallowed', $_REQUEST, NULL);
    $ourpostingstatus = $g->ourPS(array_key_exists('ourpostingstatus', $_REQUEST) ? $_REQUEST['ourpostingstatus'] : NULL);
    $filter = (Utils::presint('filter', $_REQUEST, Group::FILTER_NONE));
    $message = Utils::presdef('message', $_REQUEST, NULL);

    $ban = array_key_exists('ban', $_REQUEST) ? filter_var($_REQUEST['ban'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $modmailsonly = array_key_exists('modmailsonly', $_REQUEST) ? filter_var($_REQUEST['modmailsonly'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $logctx = Utils::presdef('logcontext', $_REQUEST, NULL);
    $collection = Utils::presdef('collection', $_REQUEST, MembershipCollection::APPROVED);
    $subject = Utils::presdef('subject', $_REQUEST, NULL);
    $body = Utils::presdef('body', $_REQUEST, NULL);
    $stdmsgid = (Utils::presint('stdmsgid', $_REQUEST, NULL));
    $action = Utils::presdef('action', $_REQUEST, NULL);
    $yps = Utils::presdef('yahooPostingStatus', $_REQUEST, NULL);
    $ydt = Utils::presdef('yahooDeliveryType', $_REQUEST, NULL);
    $ops = $g->ourPS(Utils::presdef('ourPostingStatus', $_REQUEST, NULL));

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($collection) {
        case MembershipCollection::APPROVED:
        case MembershipCollection::BANNED:
        case MembershipCollection::SPAM:
        case MembershipCollection::HAPPINESS:
        case MembershipCollection::RELATED:
        case MembershipCollection::NEARBY:
            break;
        default:
            $collection = NULL;
    }

    $u = User::get($dbhr, $dbhm, $userid);

    if ($collection) {
        switch ($_REQUEST['type']) {
            case 'GET': {
                if ($email) {
                    # We're getting a minimal set of membership information, typically from the unsubscribe page.
                    $ret = ['ret' => 3, 'status' => "We don't know that email" ];
                    $uid = $u->findByEmail($email);

                    if ($uid) {
                        $u = User::get($dbhr, $dbhm, $uid);
                        $memberships = $u->getMemberships(FALSE, Utils::presdef('grouptype', $_REQUEST, NULL));
                        $ret = ['ret' => 0, 'status' => 'Success', 'memberships' => [] ];
                        foreach ($memberships as $membership) {
                            $ret['memberships'][] = [ 'id' => $membership['id'], 'namedisplay' => $membership['namedisplay'] ];
                        }
                    }
                } else {
                    $ret = ['ret' => 1, 'status' => 'Not logged in'];

                    if ($me) {
                        $ret = ['ret' => 2, 'status' => 'Permission denied'];

                        $groupids = [];
                        $proceed = $collection === MembershipCollection::RELATED;

                        if ($groupid && ($me->isAdminOrSupport() || $me->isModOrOwner($groupid) || ($userid && $userid == $me->getId()))) {
                            # Get just one.  We can get this if we're a mod or it's our own.
                            $groupids[] = $groupid;
                            $limit = $userid ? 1 : $limit;
                            $proceed = TRUE;
                        } else if ($me->isModerator()) {
                            # No group was specified - use the current memberships, if we have any, excluding those
                            # that our preferences say shouldn't be in.  Use active memberships unless we're searching - if
                            # we are searching we want to find everything.
                            #
                            # We always show spammers, because we want mods to act on them asap.
                            $mygroups = $me->getMemberships(TRUE);
                            foreach ($mygroups as $group) {
                                if ($search || $me->activeModForGroup($group['id'])) {
                                    $proceed = TRUE;
                                    $groupids[] = $group['id'];
                                }
                            }
                        }

                        if ($proceed) {
                            if ($collection == MembershipCollection::HAPPINESS) {
                                # This is handled differently - including a different processing for filter.
                                $members = $g->getHappinessMembers($groupids, $ctx, Utils::presdef('filter', $_REQUEST, NULL), $limit);
                            } else if ($collection == MembershipCollection::NEARBY) {
                                $members = [];

                                if ($me->isModerator()) {
                                    # At the moment only mods can see this, and they can see all mods..
                                    $n = new Nearby($dbhr, $dbhm);
                                    list ($lat, $lng, $loc) = $me->getLatLng();
                                    $members = $n->getUsersNear($lat, $lng, TRUE);
                                }
                            } else if ($filter == Group::FILTER_MOSTACTIVE) {
                                # So is this
                                $members = $groupid ? $u->mostActive($groupid) : NULL;
                            } else if ($collection == MembershipCollection::RELATED) {
                                # So is this
                                if ($groupids == [-2] && $me->isAdminOrSupport()) {
                                    # We can fetch them systemwide.
                                    $groupids = NULL;
                                }

                                $members = $me->isModerator() ? $u->listRelated($groupids, $ctx, $limit) : [];
                            } else if ($filter == Group::FILTER_BANNED) {
                                # So is this
                                $members = $groupid ? $g->getBanned($groupid, $ctx) : NULL;
                            } else {
                                $members = $g->getMembers($limit, $search, $ctx, $userid, $collection, $groupids, $yps, $ydt, $ops, $filter);
                            }

                            if ($userid) {
                                $ret = [
                                    'member' => count($members) == 1 ? $members[0] : NULL,
                                    'context' => $ctx,
                                    'ret' => 0,
                                    'status' => 'Success'
                                ];
                            } else {
                                # Get some/all.
                                $ret = [
                                    'members' => $members,
                                    'groups' => [],
                                    'context' => $ctx,
                                    'ret' => 0,
                                    'status' => 'Success'
                                ];

                                foreach ($members as $m) {
                                    if (Utils::pres('groupid', $m)) {
                                        if (!Utils::pres($m['groupid'], $ret['groups'])) {
                                            $g = Group::get($dbhr, $dbhm, $m['groupid']);
                                            $ret['groups'][$m['groupid']] = $g->getPublic();
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                break;
            }

            case 'PUT': {
                $ret = ['ret' => 2, 'status' => 'Permission denied'];

                # We might have been passed a userid; if not, then assume we're acting on ourselves.
                $userid = $userid ? $userid : ($me ? $me->getId() : NULL);
                $u = User::get($dbhr, $dbhm, $userid);

                if ($u && $me && $u->getId() && $me->getId() && $groupid) {
                    $g = Group::get($dbhr, $dbhm, $groupid);
                    $origrole = $role;
                    $myrole = $me->getRoleForGroup($groupid, FALSE);

                    if ($userid && $userid != $me->getId()) {
                        # If this isn't us, we can add them, but not as someone with higher permissions than us, and
                        # if we're only a user, we can't add someone else at all.
                        $role = $myrole == User::ROLE_MEMBER ? User::ROLE_NONMEMBER : $u->roleMin($role, $myrole);

                        # ...unless there are no mods at all, in which case this lucky person could become the owner.
                        $role = ($origrole == User::ROLE_OWNER && $role == User::ROLE_MODERATOR && count($g->getMods()) == 0) ? User::ROLE_OWNER : $role;

                        # If we're allowed to add another user, then they should be added as an approved member even
                        # if the group approves members.
                        $addtocoll = MembershipCollection::APPROVED;

                        # Make sure they're not banned - an explicit add should override that.
                        $u->unban($groupid);
                    } else if ($userid) {
                        # We're adding ourselves, i.e. joining a group.
                        $addtocoll = MembershipCollection::APPROVED;

                        # But joining shouldn't demote us - we can do that via PATCH.
                        $role = $me->roleMax($role, $myrole);
                    }

                    if ($email) {
                        # Get the emailid we'd like to use on this group.  This will add it if absent.
                        $emailid = $u->addEmail($email);
                    } else {
                        # We've not asked to use a specific email address.  Just use our preferred one.
                        $emailid = $u->getAnEmailId();
                    }

                    if (!$userid || $role != User::ROLE_NONMEMBER) {
                        $u->addMembership($groupid, $role, $emailid, $addtocoll, $message);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'addedto' => $addtocoll
                        ];
                    }
                }

                break;
            }

            case 'POST': {
                $ret = ['ret' => 2, 'status' => 'Permission denied'];
                $role = $me ? $me->getRoleForGroup($groupid) : User::ROLE_NONMEMBER;

                if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                    $ret = [ 'ret' => 0, 'status' => 'Success' ];

                    switch ($action) {
                        case 'Delete Approved Member':
                            # We can remove them, but not if they are someone higher than us.
                            $myrole = $me->getRoleForGroup($groupid);
                            $ret = ['ret' => 2, 'status' => 'Permission denied'];

                            if ($myrole == $u->roleMax($myrole, $u->getRoleForGroup($groupid))) {
                                $u->mail($groupid, $subject, $body, $stdmsgid, $action);
                                $u->removeMembership($groupid);
                                $ret = [ 'ret' => 0, 'status' => 'Success' ];
                            }
                            break;
                        case 'Leave Member':
                        case 'Leave Approved Member':
                            $u->mail($groupid, $subject, $body, $stdmsgid, $action);
                            break;
                        case 'HappinessReviewed':
                            $u->happinessReviewed($happinessid);
                            break;
                        case 'Unban': {
                            $u->unban($groupid);
                            break;
                        }
                    }
                }

                break;
            }

            case 'DELETE': {
                $ret = ['ret' => 2, 'status' => 'Permission denied'];

                if ($email) {
                    # We are unsubscribing when logged out.  There is a DoS attack here, but there's a benefit in
                    # allowing users who can't manage to log in to unsubscribe.  We only allow an unsubscribe on a
                    # group as a member to avoid the DoS hitting mods.
                    $ret = ['ret' => 3, 'status' => "We don't know that email" ];
                    $uid = $u->findByEmail($email);

                    if ($uid) {
                        $u = User::get($dbhm, $dbhm, $uid);
                        $ret = ['ret' => 4, 'status' => "Can't remove from that group" ];
                        if ($u->isApprovedMember($groupid) && !$u->isModOrOwner($groupid)) {
                            $ret = ['ret' => 0, 'status' => 'Success' ];
                            $u->removeMembership($groupid);
                        }
                    }
                } else if ($u && $me && ($me->isAdminOrSupport() || $me->isModOrOwner($groupid) || $userid == $me->getId())) {
                    # We can remove them, but not if they are someone higher than us.
                    $myrole = $me->getRoleForGroup($groupid);
                    if ($myrole == $u->roleMax($myrole, $u->getRoleForGroup($groupid))) {
                        $rc = $u->removeMembership($groupid, $ban);

                        if ($rc) {
                            $ret = [
                                'ret' => 0,
                                'status' => 'Success'
                            ];
                        }
                    }
                }

                break;
            }

            case 'PATCH': {
                $ret = ['ret' => 2, 'status' => 'Permission denied'];

                if ($me) {
                    if ($me->isAdminOrSupport() || $me->isModOrOwner($groupid)) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];

                        # We don't want to default the role to anything here, otherwise we might patch ourselves
                        # to a member.
                        $role = Utils::presdef('role', $_REQUEST, NULL);

                        if ($role) {
                            # We can set the role, but not to something higher than our own.
                            $role = $u->roleMin($role, $me->getRoleForGroup($groupid));
                            $u->setRole($role, $groupid);
                        }

                        if (Utils::pres('configid', $settings)) {
                            # We want to change the config that we use to mod this group.  Check that the config id
                            # passed is one to which we have access.
                            $configs = $me->getConfigs(TRUE);

                            foreach ($configs as $config) {
                                if ($config['id'] == $settings['configid']) {
                                    $c = new ModConfig($dbhr, $dbhm, $config['id']);
                                    $c->useOnGroup($me->getId(), $groupid);
                                }
                            }

                            unset($settings['configid']);
                        }
                    }

                    if ($me->isModOrOwner($groupid) || $me->getId() == $userid) {
                        # We can change settings for a user if we're a mod or they are our own
                        $rc = TRUE;

                        if ($settings) {
                            $rc &= $u->setGroupSettings($groupid, $settings);
                        }

                        if ($emailfrequency !== NULL) {
                            $rc &= $u->setMembershipAtt($groupid, 'emailfrequency', intval($emailfrequency));
                        }

                        if ($eventsallowed !== NULL) {
                            $rc &= $u->setMembershipAtt($groupid, 'eventsallowed', intval($eventsallowed));
                        }

                        if ($volunteeringallowed !== NULL) {
                            $rc &= $u->setMembershipAtt($groupid, 'volunteeringallowed', intval($volunteeringallowed));
                        }

                        if ($ourpostingstatus !== NULL) {
                            $rc &= $u->setMembershipAtt($groupid, 'ourPostingStatus', $ourpostingstatus);
                        }

                        $ret = $rc ? [ 'ret' => 0, 'status' => 'Success' ] : [ 'ret' => 2, 'status' => 'Set failed' ];
                    }
                }

                break;
            }
        }
    } else {
        $ret = [ 'ret' => 3, 'status' => 'Bad collection' ];
    }

    return($ret);
}
