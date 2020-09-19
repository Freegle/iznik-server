<?php
namespace Freegle\Iznik;

function src() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'POST': {
            $me = Session::whoAmI($dbhr, $dbhm);
            $dbhm->background("INSERT INTO logs_src (src, userid, session) VALUES (" . $dbhm->quote($_REQUEST['src']) . ", " . $dbhm->quote(Utils::presdef('id', $_SESSION, NULL)) . ", " . $dbhm->quote(session_id()) . ");");

            # Record in the session, as we might later create a user.
            $_SESSION['src'] = $_REQUEST['src'];

            $ret = [
                'ret' => 0,
                'status' => 'Success'
            ];
            break;
        }
    }

    return($ret);
}
