<?php
namespace Freegle\Iznik;

function logs() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $logtype = Utils::presdef('logtype', $_REQUEST, NULL);
    $logsubtype = Utils::presdef('logsubtype', $_REQUEST, NULL);
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));
    $userid = (Utils::presint('userid', $_REQUEST, NULL));
    $date = (Utils::presint('date', $_REQUEST, NULL));
    $search = Utils::presdef('search', $_REQUEST, NULL);
    $ctx = Utils::presdef('context', $_REQUEST, NULL);
    $limit = (Utils::presint('limit', $_REQUEST, 20));
    $modmailsonly = Utils::presbool('modmailsonly', $_REQUEST, FALSE);

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 2, 'status' => 'Not moderator' ];

            if ($me) {
                $l = new Log($dbhr, $dbhm);

                $ctx = $ctx ? $ctx : [];

                switch ($logtype) {
                    case 'messages': {
                        if ($me->isAdminOrSupport() || $me->isModOrOwner($groupid)) {
                            $types = [Log::TYPE_MESSAGE];
                            $subtypes = $logsubtype ? [$logsubtype] : [
                                Log::SUBTYPE_RECEIVED,
                                Log::SUBTYPE_APPROVED,
                                Log::SUBTYPE_REJECTED,
                                Log::SUBTYPE_DELETED,
                                Log::SUBTYPE_AUTO_REPOSTED,
                                Log::SUBTYPE_AUTO_APPROVED,
                                Log::SUBTYPE_OUTCOME
                            ];

                            $ret = [ 'ret' => 0, 'status' => 'Success' ];
                            $ret['logs'] = $l->get(
                                $types,
                                $subtypes,
                                $groupid,
                                $userid,
                                $date,
                                $search,
                                $limit,
                                $ctx
                            );
                        }
                        break;
                    }
                    case 'memberships': {
                        if ($me->isAdminOrSupport() || $me->isModOrOwner($groupid)) {
                            $types = [Log::TYPE_GROUP, Log::TYPE_USER];
                            $subtypes = $logsubtype ? [$logsubtype] : [
                                Log::SUBTYPE_JOINED,
                                Log::SUBTYPE_REJECTED,
                                Log::SUBTYPE_APPROVED,
                                Log::SUBTYPE_APPLIED,
                                Log::SUBTYPE_AUTO_APPROVED,
                                Log::SUBTYPE_LEFT
                            ];

                            $ret = [ 'ret' => 0, 'status' => 'Success' ];

                            $ret['logs'] = $l->get(
                                $types,
                                $subtypes,
                                $groupid,
                                $userid,
                                $date,
                                $search,
                                $limit,
                                $ctx
                            );
                        }
                        break;
                    }
                    case 'user': {
                        $u = User::get($dbhr, $dbhm, $userid);

                        if ($me->isModerator()) {
                            $logs = [$userid => ['id' => $userid]];
                            $u->getPublicLogs($u, $logs, $modmailsonly, $ctx);
                            $ret = [ 'ret' => 0, 'status' => 'Success' ];
                            $ret['logs'] = $logs[$userid]['logs'];
                        }
                    }
                }
            }

            $ret['context'] = $ctx;
        }
        break;
    }

    return($ret);
}
