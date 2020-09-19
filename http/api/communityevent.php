<?php
namespace Freegle\Iznik;

function communityevent() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);
    $myid = $me ? $me->getId() : NULL;

    $id = intval(Utils::presdef('id', $_REQUEST, NULL));
    $groupid = intval(Utils::presdef('groupid', $_REQUEST, NULL));

    if ($groupid) {
        # This might be a legacy groupid.
        $g = Group::get($dbhr, $dbhm, $groupid);
        $groupid = $g->getId();
    }

    $pending = array_key_exists('pending', $_REQUEST) ? filter_var($_REQUEST['pending'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $ctx = Utils::presdef('context', $_REQUEST, NULL);
    
    $c = new CommunityEvent($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    if ($id && $c->getId() || $_REQUEST['type'] == 'POST' || (!$id && $_REQUEST['type'] == 'GET')) {
        switch ($_REQUEST['type']) {
            case 'GET': {
                if ($id) {
                    # We're not bothered about privacy of events - people may not be logged in when they see them.
                    $ret = [ 'ret' => 3, 'status' => 'Deleted' ];

                    if (!$c->getPrivate('deleted')) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'communityevent' => $c->getPublic()
                        ];

                        $ret['communityevent']['canmodify'] = $c->canModify($myid);
                    }
                } else if ($groupid) {
                    # List for a specific group - which we can do even if not logged in.
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'communityevents' => $c->listForGroup($pending, $groupid, $ctx),
                        'context' => $ctx
                    ];
                } else {
                    # List all for this user.
                    $ret = ['ret' => 1, 'status' => 'Not logged in'];

                    if ($me) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'communityevents' => $c->listForUser($me->getId(), $pending, $ctx),
                            'context' => $ctx
                        ];
                    }
                }
                break;
            }

            case 'POST': {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];

                if ($me) {
                    $title = $location = $contactname = $contactphone = $contactemail = $contacturl = $description = $photo = NULL;

                    foreach (['title', 'location', 'contactname', 'contactphone', 'contactemail', 'contacturl', 'description', 'photo'] as $att) {
                        $$att = Utils::presdef($att, $_REQUEST, NULL);
                    }

                    $id = NULL;

                    if ($title && $location && $description) {
                        $id = $c->create($me->getId(), $title, $location, $contactname, $contactphone, $contactemail, $contacturl, $description, $photo);
                    }

                    $ret = $id ? ['ret' => 0, 'status' => 'Success', 'id' => $id] : ['ret' => 2, 'status' => 'Create failed'];
                }

                break;
            }

            case 'PUT':
                $ret = ['ret' => 1, 'status' => 'Not logged in'];

                if ($me && $c->canModify($me->getId())) {
                    $c->setAttributes($_REQUEST);
                    
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
                break;

            case 'PATCH': {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];

                if ($me && $c->canModify($me->getId())) {
                    $c->setAttributes($_REQUEST);

                    switch (Utils::presdef('action', $_REQUEST, NULL)) {
                        case 'AddGroup': $c->addGroup(intval(Utils::presdef('groupid', $_REQUEST, 0))); break;
                        case 'RemoveGroup': $c->removeGroup(intval(Utils::presdef('groupid', $_REQUEST, 0))); break;
                        case 'AddDate': $c->addDate(Utils::presdef('start', $_REQUEST, NULL), Utils::presdef('end', $_REQUEST, NULL)); break;
                        case 'RemoveDate': $c->removeDate(intval(Utils::presdef('dateid', $_REQUEST, NULL))); break;
                        case 'SetPhoto': $c->setPhoto(intval(Utils::presdef('photoid', $_REQUEST, NULL))); break;
                        case 'Hold':
                            if ($me->isModerator()) {
                                $c->setPrivate('heldby', $me->getId());
                            }
                            break;
                        case 'Release':
                            if ($me->isModerator()) {
                                $c->setPrivate('heldby', NULL);
                            }
                            break;
                    }

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
                break;
            }

            case 'DELETE': {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];

                if ($me && $c->canModify($me->getId())) {
                    $c->delete();

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
                break;
            }
        }
    } else {
        $ret = [
            'ret' => 2,
            'status' => 'Invalid id'
        ];
    }

    return($ret);
}
