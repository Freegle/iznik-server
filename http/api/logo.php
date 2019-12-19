<?php
function logo() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 0, 'status' => 'Success'];

            $logos = $dbhr->preQuery("SELECT * FROM logos WHERE `date` LIKE ? AND active = 1 ORDER BY RAND();", [
                date("m-d")
            ]);

            foreach ($logos as $logo) {
                $ret['logo'] = $logo;

                # Return full path for the mobile app.
                $ret['logo']['path'] = "https://" . IMAGE_DOMAIN . $ret['logo']['path'];
            }

            break;
        }
    }

    return($ret);
}
