<?php
function stroll() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Something wrong'];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $route = $dbhr->preQuery("SELECT * FROM stroll_route ORDER BY id ASC");
            $sponsors = $dbhr->preQuery("SELECT * FROM stroll_sponsors ORDER BY id ASC");
            $nights = $dbhr->preQuery("SELECT * FROM stroll_nights ORDER BY id ASC");

            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'route' => $route,
                'sponsors' => $sponsors,
                'nights' => $nights
            ];

            break;
        }

        case 'POST': {
            $sponsorname = presdef('sponsorname', $_REQUEST);

            if ($sponsorname) {
                $dbhm->preExec("INSERT INTO stroll_sponsors (name) VALUES (?)", [
                    $sponsorname
                ]);
            }
        }
    }

    return($ret);
}
