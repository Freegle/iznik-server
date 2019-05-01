<?php
function stroll() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Something wrong'];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $route = $dbhr->preQuery("SELECT * FROM stroll_route ORDER BY id ASC");
            $sponsors = $dbhr->preQuery("SELECT * FROM stroll_sponsors ORDER BY timestamp ASC");
            $nights = $dbhr->preQuery("SELECT * FROM stroll_nights ORDER BY id ASC");

            foreach ($sponsors as &$sponsor) {
                $sponsor['timestamp'] = ISODate($sponsor['timestamp']);
            }

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
            $sponsorname = presdef('sponsorname', $_REQUEST, NULL);
            error_log("Sponsor $sponsorname");

            if ($sponsorname) {
                $dbhm->preExec("INSERT INTO stroll_sponsors (name) VALUES (?)", [
                    $sponsorname
                ]);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $dbhm->lastInsertId()
                ];
            }
        }
    }

    return($ret);
}
