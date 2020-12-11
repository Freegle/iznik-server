<?php
namespace Freegle\Iznik;

function spammers() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = (Utils::presint('id', $_REQUEST, NULL));
    $userid = (Utils::presint('userid', $_REQUEST, NULL));
    $collection = Utils::presdef('collection', $_REQUEST, Spam::TYPE_SPAMMER);
    $reason = Utils::presdef('reason', $_REQUEST, NULL);
    $context = Utils::presdef('context', $_REQUEST, NULL);
    $search = Utils::presdef('search', $_REQUEST, NULL);
    $heldby = Utils::presint('heldby', $_REQUEST, NULL);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($collection) {
        case Spam::TYPE_SPAMMER:
        case Spam::TYPE_WHITELIST:
        case Spam::TYPE_PENDING_ADD:
        case Spam::TYPE_PENDING_REMOVE:
            break;
        default:
            $collection = NULL;
    }

    $s = new Spam($dbhr, $dbhm);
    $ret = ['ret' => 1, 'status' => 'Not logged in'];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];
            $cansee = $me && $me->isModerator();
            $cansee = $cansee ? $cansee : Session::partner($dbhr, Utils::presdef('partner', $_REQUEST, NULL))[0];

            if ($cansee) {
                # Only mods or partners can see the list.
                if (Utils::presdef('action', $_REQUEST, NULL) == 'export') {
                    $ret = [ 'ret' => 0, 'status' => 'Success', 'spammers' => $s->exportSpammers() ];
                } else {
                    $ret = [ 'ret' => 0, 'status' => 'Success', 'spammers' => $s->listSpammers($collection, $search, $context) ];
                    $ret['context'] = $context;
                }
            }
            break;
        }

        case 'POST': {
            if ($me) {
                if ($me->isAdminOrSupport() || $collection == Spam::TYPE_PENDING_ADD) {
                    # Admin/Support can do anything; anyone can submit a spammer.
                    $ret = ['ret' => 0, 'status' => 'Success', 'id' => $s->addSpammer($userid, $collection, $reason)];
                } else {
                    # Not allowed
                    $ret = ['ret' => 2, 'status' => 'Permission denied' ];
                }
            }
            break;
        }

        case 'PATCH': {
            if ($me) {
                $spammer = $s->getSpammer($id);
                $ret = ['ret' => 2, 'status' => 'Permission denied'];

                if ($spammer) {
                    if ($me->isAdminOrSupport() || $me->hasPermission(User::PERM_SPAM_ADMIN)) {
                        # Admin/Support can do anything
                        $ret = ['ret' => 0, 'status' => 'Success', 'id' => $s->updateSpammer($id, $spammer['userid'], $collection, $reason, $heldby)];
                    } else if ($me->isModerator() &&
                        ($spammer['collection'] == Spam::TYPE_SPAMMER) &&
                        ($collection == Spam::TYPE_PENDING_REMOVE)) {
                        # Mods can request removal
                        $ret = ['ret' => 0, 'status' => 'Success', 'id' => $s->updateSpammer($id, $spammer['userid'], $collection, $reason, NULL)];
                    }
                }
            }
            break;
        }

        case 'DELETE': {
            if ($me) {
                $ret = ['ret' => 2, 'status' => 'Permission denied'];

                if ($me->isAdminOrSupport()) {
                    # Only Admin/Support can remove.
                    $ret = [ 'ret' => 0, 'status' => 'Success' ];
                    $s->deleteSpammer($id, $reason);
                }
            }
            break;
        }
    }

    return($ret);
}
