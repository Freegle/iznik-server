<?php
function stroll() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Something wrong'];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $route = $dbhr->preQuery("SELECT * FROM stroll_route ORDER BY id ASC");

            # Sponsors include people who've gone via the dedicated page, people who've donated via Facebook (because
            # that's the only fundraising that's happening on Facebook).
            #
            # We're also promoting the stroll on our normal donation requests, but we can't count those because a)
            # we'd double-count donations via the dedicated page and b) we would get some donations anyway.
            $sponsors = $dbhr->preQuery("SELECT * FROM stroll_sponsors ORDER BY timestamp ASC");

            foreach ($sponsors as &$sponsor) {
                $sponsor['timestamp'] = ISODate($sponsor['timestamp']);
            }

            $facebooks = $dbhr->preQuery("SELECT * FROM users_donations WHERE source = 'Facebook';");

            foreach ($facebooks as $facebook) {
                $sponsors[] = [
                    'name' => 'Anonymous Facebook donation',
                    'amount' => $facebook['GrossAmount'],
                    'timestamp' => ISODate($facebook['timestamp'])
                ];
            }

            usort($sponsors, function($a, $b) {
                return(strcmp($a['timestamp'], $b['timestamp']));
            });

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
            $sponsorname = presdef('sponsorname', $_REQUEST, NULL);

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
