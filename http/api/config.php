<?php
namespace Freegle\Iznik;

function config() {
    global $dbhr, $dbhm;

    $key = Utils::presdef('key', $_REQUEST, NULL);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($key) {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'values' => $dbhr->preQuery("SELECT * FROM config WHERE `key` LIKE ?;", [
                        $key
                    ])
                ];
            }
            break;
        }
    }

    return($ret);
}
