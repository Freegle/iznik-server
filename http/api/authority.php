<?php
namespace Freegle\Iznik;

function authority() {
    global $dbhr, $dbhm;

    $id = (Utils::presint('id', $_REQUEST, NULL));
    $search = Utils::presdef('search', $_REQUEST, NULL);
    $stats = array_key_exists('stats', $_REQUEST) ? filter_var($_REQUEST['stats'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $start = Utils::presdef('start', $_REQUEST, '365 days ago');
    $end = Utils::presdef('end', $_REQUEST, 'today');

    $a = new Authority($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($id) {
                $atts = $a->getPublic();
                if ($stats) {
                    $s = new Stats($dbhr, $dbhm);
                    $atts['stats'] = $s->getByAuthority([ $id ], $start, $end);
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'authority' => $atts
                ];
            } else if ($search) {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'authorities' => $a->search($search)
                ];
            }
            break;
        }
    }

    return($ret);
}
