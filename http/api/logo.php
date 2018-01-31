<?php
function logo() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 0, 'status' => 'Success'];

            $logos = $dbhr->preQuery("SELECT * FROM logos WHERE `date` LIKE ?;", [
                date("m-d")
            ]);

            foreach ($logos as $logo) {
                $ret['logo'] = $logo;
            }

            break;
        }
    }

    return($ret);
}
