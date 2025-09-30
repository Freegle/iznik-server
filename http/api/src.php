<?php
namespace Freegle\Iznik;

function src() {
    // TODO: DEPRECATED - This endpoint has been migrated to v2 Go API
    // Can be retired once all FD clients are using v2
    // Migrated: 2025-09-29
    // V2 endpoint: POST /apiv2/src
    // Used by: FD only (not MT) for tracking source parameters from URLs

    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'POST': {
            $dbhm->background("INSERT INTO logs_src (src, userid, session) VALUES (" . $dbhm->quote($_REQUEST['src']) . ", " . $dbhm->quote(Utils::presdef('id', $_SESSION, NULL)) . ", " . $dbhm->quote(session_id()) . ");");

            $me = Session::whoAmI($dbhr, $dbhm);
            if ($me && !$me->getPrivate('source')) {
                $me->setPrivate('source', $_REQUEST['src']);
            }

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
