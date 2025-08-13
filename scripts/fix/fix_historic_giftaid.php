<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

if (($handle = fopen("/tmp/giftaid.csv", "r")) !== FALSE)
{
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE)
    {
        $date = $data[0];
        $type = $data[1];
        $name = $data[4] . " " . $data[5];
        $address = $data[6];
        $postcode = $data[7];
        $amount = $data[9];
        $email = $data[11];

        error_log("$date, $type from $name address $address, $postcode for $amount email $email");
        $u = new User($dbhr, $dbhm);
        $uid = $u->findByEmail($email);

        if ($uid) {
            error_log("Found $uid");
            $d = new Donations($dbhr, $dbhm);
            $giftaid = $d->getGiftAid($uid);

            if ($giftaid) {
                error_log("...already got gift aid for $email");
            } else {
                error_log("...not got consent");
                $d->ad
            }
        } else {
            error_log("Couldn't find $email");
        }
    }
}
