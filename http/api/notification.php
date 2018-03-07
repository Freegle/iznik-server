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
                } else {
                    $ctx = presdef('context', $_REQUEST, NULL);

                    $ret = [
                        'ret' => 0,
                        'context' => $ctx,
                        'status' => 'Success',
                        'notifications' => $n->get($me->getId(), $ctx)
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
