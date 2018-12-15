<?php
function authority() {
    global $dbhr, $dbhm;

    $id = intval(presdef('id', $_REQUEST, NULL));
    $search = presdef('search', $_REQUEST, NULL);
    $stats = array_key_exists('stats', $_REQUEST) ? filter_var($_REQUEST['stats'], FILTER_VALIDATE_BOOLEAN) : FALSE;

    $a = new Authority($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($id) {
                $atts = $a->getPublic();
                if ($stats) {
                    $s = new Stats($dbhr, $dbhm);
                    $atts['stats'] = $s->getByAuthority($id);
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
