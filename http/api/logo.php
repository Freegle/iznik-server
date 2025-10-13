<?php
namespace Freegle\Iznik;

// TODO: DEPRECATED - This endpoint has been migrated to v2 Go API
// Can be retired once all FD/MT clients are using v2
// Migrated: 2025-10-13
// V2 endpoints: GET /logo
// Used by: Both FD and MT for fetching special date-specific logos

function logo() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 0, 'status' => 'Success', 'date' => date("m-d")];

            $logos = $dbhr->preQuery("SELECT * FROM logos WHERE `date` LIKE ? AND active = 1 ORDER BY RAND();", [
                date("m-d")
            ]);

            foreach ($logos as $logo) {
                $ret['logo'] = $logo;

                # Return full path for the mobile app.
                $ret['logo']['path'] = "https://" . USER_SITE . str_replace('/images', '', $ret['logo']['path']);
            }

            break;
        }
    }

    return($ret);
}
