<?php
namespace Freegle\Iznik;

function notification() {
    global $dbhr, $dbhm;

    # We don't need the full user object - save time by not getting it.
    $myid = Session::whoAmI($dbhr, $dbhm, TRUE);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $n = new Notifications($dbhr, $dbhm);
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $count = array_key_exists('count', $_REQUEST) ? filter_var($_REQUEST['count'], FILTER_VALIDATE_BOOLEAN) : FALSE;

                if ($count) {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'count' => $n->countUnseen($myid)
                    ];

                    if (!Session::modtools()) {
                        # This request occurs every 30 seconds, so we can piggyback on it to spot when users are active.
                        $me = Session::whoAmI($dbhr, $dbhm);
                        $me->recordActive();
                    }
                } else {
                    $ctx = Utils::presdef('context', $_REQUEST, NULL);
                    $notifs = $n->get($myid, $ctx);
                    #error_log("Notification context " . var_export($ctx, TRUE));

                    $ret = [
                        'ret' => 0,
                        'context' => $ctx,
                        'status' => 'Success',
                        'notifications' => $notifs
                    ];
                }

                break;
            }

            case 'POST': {
                $id = (Utils::presint('id', $_REQUEST, NULL));
                $action = Utils::presdef('action', $_REQUEST, NULL);

                switch ($action) {
                    case 'Seen': {
                        $n->seen($myid, $id);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                        break;
                    }

                    case 'AllSeen': {
                        $n->seen($myid);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                        break;
                    }
                }
                break;
            }
        }
    }

    return($ret);
}
