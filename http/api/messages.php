<?php
namespace Freegle\Iznik;

function messages() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));
    $groupids = Utils::presdef('groupids', $_REQUEST, NULL);
    $collection = Utils::presdef('collection', $_REQUEST, MessageCollection::APPROVED);
    $ctx = Utils::presdef('context', $_REQUEST, NULL);
    $limit = (Utils::presint('limit', $_REQUEST, 5));
    $fromuser = Utils::presdef('fromuser', $_REQUEST, NULL);
    $hasoutcome = array_key_exists('hasoutcome', $_REQUEST) ? filter_var($_REQUEST['hasoutcome'], FILTER_VALIDATE_BOOLEAN) : NULL;
    $types = Utils::presdef('types', $_REQUEST, NULL);
    $subaction = Utils::presdef('subaction', $_REQUEST, NULL);
    $summary = array_key_exists('summary', $_REQUEST) ? filter_var($_REQUEST['summary'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $grouptype = Utils::presdef('grouptype', $_REQUEST, NULL);
    $exactonly = array_key_exists('exactonly', $_REQUEST) ? filter_var($_REQUEST['exactonly'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $searchmygroups = array_key_exists('searchmygroups', $_REQUEST) ? filter_var($_REQUEST['searchmygroups'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $swlat = Utils::presfloat('swlat', $_REQUEST, NULL);
    $swlng = Utils::presfloat('swlng', $_REQUEST, NULL);
    $nelat = Utils::presfloat('nelat', $_REQUEST, NULL);
    $nelng = Utils::presfloat('nelng', $_REQUEST, NULL);

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            # Ensure that if we aren't using any groups, we don't treat this as a systemwide search, because that
            # kills the DB.
            $groups = [0];
            $userids = [];

            if ($collection != MessageCollection::DRAFT) {
                if ($subaction == 'searchall' && $me && $me->isAdminOrSupport()) {
                    # We are intentionally searching the whole system, and are allowed to.
                } else if ($groupid) {
                    # A group was specified
                    $groups[] = $groupid;
                } else if ($groupids) {
                    # Group ids were specified.
                    foreach ($groupids as $groupid) {
                        $groups[] = intval($groupid);
                    }
                } else if ($fromuser) {
                    # We're searching for messages from a specific user, so skip the group filter.  This
                    # handles the case where someone joins, posts, leaves, and then can't see their posts
                    # in My Posts.
                    $groups = NULL;
                } else if ($me) {
                    # No group was specified - use the current memberships, if we have any, excluding those that our
                    # preferences say shouldn't be in.
                    #
                    # If we're in Freegle Direct, we only want to show Freegle groups.
                    $mygroups = $me->getMemberships(Session::modtools(), Session::modtools() ? $grouptype : Group::GROUP_FREEGLE);
                    foreach ($mygroups as $group) {
                        $settings = $me->getGroupSettings($group['id']);
                        if (!Session::modtools() || !array_key_exists('active', $settings) || $settings['active']) {
                            $groups[] = $group['id'];
                        }
                    }
                }
            }

            if ($fromuser) {
                # We're looking for messages from a specific user
                $userids[] = $fromuser;
            }

            $msgs = NULL;

            # If we are trying to get Pending and we're not logged in, that's an error.
            $ret = [
                'ret' => 1,
                'status' => 'Not logged in'
            ];

            if ($collection != MessageCollection::PENDING || $me) {
                $c = new MessageCollection($dbhr, $dbhm, $collection);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'searchgroups' => $groups,
                    'searchgroup' => $groupid
                ];

                switch ($subaction) {
                    case NULL:
                        # Just a normal fetch.
                        if ($collection === MessageCollection::ALLUSER) {
                            $age = MessageCollection::OWNPOSTS;

                            # Always want all data for own posts no matter what the client says.
                            $summary = FALSE;
                        } else {
                            $age = Utils::presint('age', $_REQUEST, NULL);
                        }

                        list($groups, $msgs) = $c->get($ctx, $limit, $groups, $userids, Message::checkTypes($types), $age, $hasoutcome, $summary);
                        $m = new Message($dbhr, $dbhm);

                        foreach ($msgs as &$msg) {
                            $m->checkLoveJunk($msg);
                        }

                        break;
                    case 'mygroups': {
                        $groups = [];
                        $msgs = [];

                        if ($me) {
                            $mygroups = $me->getMembershipGroupIds(FALSE, $grouptype, NULL);
                            $msgs = $c->getByGroups($mygroups, $ctx, $ctx ? $limit : NULL);
                        }
                        break;
                    }
                    case 'inbounds': {
                        $groups = [];
                        $msgs = $c->getInBounds($swlat, $swlng, $nelat, $nelng, $groupid);
                        break;
                    }
                    case 'search':
                    case 'searchmess':
                    case 'searchall':
                        # A search on message info.
                        $search = Utils::presdef('search', $_REQUEST, NULL);
                        $search = $search ? trim($search) : NULL;
                        $ctx = Utils::presdef('context', $_REQUEST, NULL);
                        $limit = Utils::presint('limit', $_REQUEST, Search::Limit);
                        $messagetype = Utils::presdef('messagetype', $_REQUEST, NULL);
                        $nearlocation = Utils::presdef('nearlocation', $_REQUEST, NULL);
                        $nearlocation = $nearlocation ? intval($nearlocation) : NULL;

                        if (is_numeric($search)) {
                            $m = new Message($dbhr, $dbhm, $search);

                            if ($m->getID() == $search) {
                                # Found by message id.
                                list($groups, $msgs) = $c->fillIn([['id' => $search]], $limit, null, $summary);
                            }
                        } else if ($swlat !== NULL && $swlng !== NULL && $nelat !== NULL && $nelng !== NULL) {
                            $m = new Message($dbhr, $dbhm);
                            $msgs = $m->searchActiveInBounds($search, $messagetype, $swlat, $swlng, $nelat, $nelng, $groupid, $exactonly);
                        } else if ($searchmygroups) {
                            $mygroups = [];

                            if ($groupid) {
                                $mygroups = [ $groupid ];
                            } else if ($me) {
                                $mygroups = $me->getMembershipGroupIds(FALSE, $grouptype, NULL);
                            }

                            $m = new Message($dbhr, $dbhm);
                            $msgs = count($mygroups) ? $m->searchActiveInGroups($search, $messagetype, $exactonly, $mygroups) : [];
                        } else {
                            # Search near location.
                            $m = new Message($dbhr, $dbhm);

                            $searchgroups = $groupid ? [ $groupid ] : NULL;

                            if ($nearlocation) {
                                # We need to look in the groups near this location.
                                $l = new Location($dbhr, $dbhm, $nearlocation);
                                $searchgroups = $l->groupsNear();
                            }

                            do {
                                $searched = $m->search($search, $ctx, $limit, NULL, $searchgroups, $nearlocation, $exactonly);
                                list($groups, $msgs) = $c->fillIn($searched, $limit, $messagetype, FALSE);
                                # We might have excluded all the messages we found; if so, keep going.
                            } while (count($searched) > 0 && count($msgs) == 0);
                        }

                        break;
                    case 'searchmemb':
                        # A search for messages based on member.  It is most likely that this is a search where relatively
                        # few members match, so it is quickest for us to get all the matching members, then use a context
                        # to return paged results within those.  We put a fallback limit on the number of members to stop
                        # ourselves exploding, though.
                        $search = Utils::presdef('search', $_REQUEST, NULL);
                        $search = $search ? trim($search) : NULL;
                        $ctx = Utils::presdef('context', $_REQUEST, NULL);
                        $limit = Utils::presint('limit', $_REQUEST, Search::Limit);

                        $groupids = $groupid ? [ $groupid ] : NULL;

                        $g = Group::get($dbhr, $dbhm);
                        $membctx = NULL;
                        $members = $g->getMembers(1000, $search, $membctx, NULL, $collection, $groupids, NULL, NULL, NULL);
                        $userids = [];
                        foreach ($members as $member) {
                            $userids[] = $member['userid'];
                        }

                        $members = NULL;
                        $groups = [];
                        $msgs = [];

                        if (count($userids) > 0) {
                            # Now get the messages for those members.
                            $c = new MessageCollection($dbhr, $dbhm, $collection);
                            list ($groups, $msgs) = $c->get($ctx, $limit, $groupids, $userids, $collection == MessageCollection::ALLUSER ?  MessageCollection::OWNPOSTS : NULL);
                        }
                        break;
                }
            }

            $ret['context'] = $ctx;
            $ret['groups'] = $groups;
            $ret['messages'] = $msgs;
        }
        break;
    }

    return($ret);
}
