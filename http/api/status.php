<?php
namespace Freegle\Iznik;

function status() {
    $tries = 0;

    do {
        $status = @file_get_contents('/tmp/iznik.status');

        $ret = [ 'ret' => 1, 'status' => 'Cannot access status file', 'error' => error_get_last() ];

        if ($status) {
            $ret = json_decode($status, TRUE);
            break;
        } else {
            sleep(1);
            $tries++;
        }
    } while ($tries < 3);

    return($ret);
}
