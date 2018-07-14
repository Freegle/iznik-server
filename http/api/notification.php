<?php
function notification() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($me) {
        $n = new Notifications($dbhr, $dbhm);
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $count = array_key_exists('count', $_REQUEST) ? filter_var($_REQUEST['count'], FILTER_VALIDATE_BOOLEAN) : FALSE;

                if ($count) {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'count' => $n->countUnseen($me->getId())
                    ];

                    # This request occurs every 30 seconds, so we can piggyback on it to spot when users are active.
                    $me->recordActive();
                } else {
                    $ctx = presdef('context', $_REQUEST, NULL);
                    $notifs = $n->get($me->getId(), $ctx);
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
                $id = intval(presdef('id', $_REQUEST, NULL));
                $action = presdef('action', $_REQUEST, NULL);

                switch ($action) {
                    case 'Seen': {
                        $n->seen($me->getId(), $id);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                        break;
                    }

                    case 'AllSeen': {
                        $n->seen($me->getId());

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
