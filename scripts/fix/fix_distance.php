<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$votes = $dbhr->preQuery("SELECT * FROM ratings;");
$res = [];

foreach ($votes as $vote) {
    $rater = new User($dbhr, $dbhm, $vote['rater']);
    $ratee = new User($dbhr, $dbhm, $vote['ratee']);

    list ($erlat, $erlng) = $rater->getLatLng(FALSE, FALSE);
    list ($eelat, $eelng) = $ratee->getLatLng(FALSE, FALSE);

    if ($erlat && $erlng && $eelat && $eelng) {
        $per = new POI($erlat, $erlng);
        $pee = new POI($eelat, $eelng);
        $metres = $per->getDistanceInMetersTo($pee);

        #error_log("{$vote['rater']} ($erlat, $erlng) and {$vote['ratee']} ($eelat, $eelng) dist $metres");
    }

    #if ($metres < 50000)
    {
        $res[] = [ $metres, $vote['rating'] ];
    }
}

$f = fopen("/tmp/dist.csv", "w");
foreach ($res as $r) {
    fputcsv($f, $r);
}

fclose($f);