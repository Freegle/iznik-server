<?php
namespace Freegle\Iznik;

function test() {
    global $dbhr, $dbhm;

    $action = Utils::presdef('action', $_REQUEST, NULL);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($action == 'SetupDB') {
                // TODO: run setup code

                $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
            } else {
                $ret = [
                    'ret' => 100,
                    'status' => 'Unknown action'
                ];
            }

            break;
        }
        
    }

    return($ret);
}
