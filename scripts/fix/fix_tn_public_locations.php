<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';

require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
global $dbhr, $dbhm;

$tnusers = [];

$fh = fopen('/tmp/tn-user-public-locations.csv', 'r');

$count = 0;

while (!feof($fh))
{
    $fields = fgetcsv($fh);
    $tnid = $fields[0];
    $tnname = $fields[1];
    $tnfdid = $fields[2];
    $lat = $fields[3];
    $lng = $fields[3];
    $tnesc = str_replace('_', '\_', $tnname);
    $emails = $dbhm->preQuery("SELECT * FROM users_emails WHERE email LIKE '$tnesc-g%@user.trashnothing.com';");

    if (count($emails) > 0) {
        $fdid = $emails[0]['userid'];

        $u = new User($dbhr, $dbhm, $fdid);

        $l = new Location($dbhr, $dbhm);

        $loc = $l->closestPostcode($lat, $lng);

        if ($loc) {
            #error_log("...found postcode {$loc['id']} {$loc['name']}");

            if ($loc['id'] !== $u->getPrivate('locationid')) {
                error_log("FD #$fdid TN lat/lng $lat,$lng has changed {$u->getPrivate('locationid')} => {$loc['id']} {$loc['name']}");
                $u->setPrivate('lastlocation', $loc['id']);
            }
        }
    } else {
        error_log("Couldn't find TN user for $tnname");
    }

    $count++;
    if ($count % 1000 === 0) {
        error_log("...$count");
    }
}
