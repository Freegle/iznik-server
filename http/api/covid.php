<?php
function covid() {
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);

    $helptype = presdef('helptype', $_REQUEST, NULL);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'PUT': {
            $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

            if ($me && $helptype) {
                $dbhm->preExec("INSERT INTO covid (userid, type) VALUES (?, ?) ON DUPLICATE KEY UPDATE type = ?;", [
                    $me->getId(),
                    $helptype,
                    $helptype
                ]);

                $ret = [ 'ret' => 0, 'status' => 'Success' ];
            }
            break;
        }
    }

    return($ret);
}
