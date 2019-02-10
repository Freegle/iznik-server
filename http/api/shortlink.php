<?php
function shortlink() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $me = whoAmI($dbhr, $dbhm);

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = ['ret' => 2, 'status' => 'Permission denied'];

            if ($me && $me->isModerator()) {
                $s = new Shortlink($dbhr, $dbhm);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'shortlinks' => $s->listAll()
                ];
            }

            break;
        }

        case 'POST': {

        }
    }

    return($ret);
}
