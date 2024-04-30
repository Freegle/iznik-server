<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:');
$fn = $opts['f'];
$fh = fopen($fn, 'r');

$failed = [];

while (!feof($fh)) {
    $fields = fgetcsv($fh);
    $name = $fields[1] . " " . $fields[2];
    $pc = str_replace(' ', '', $fields[4]);

    if ($name && $pc) {
        $giftaid = $dbhr->preQuery("SELECT * FROM giftaid WHERE fullname LIKE ? AND REPLACE(homeaddress, \" \", \"\") LIKE '%$pc%'", [ $name ]);

        if (count($giftaid) == 1) {
            $u = User::get($dbhr, $dbhm, $giftaid[0]['userid']);

            if ($u && $u->getId() == $giftaid[0]['userid']) {
                $fields[] = $u->getEmailPreferred();
                fputcsv(STDOUT, $fields);
            } else {
                error_log("Failed to find user for giftaid {$giftaid[0]['userid']}");
                fputcsv(STDOUT, $fields);
            }
        } else {
            $failed[$name] = $pc;
            fputcsv(STDOUT, $fields);
        }
    }
}

foreach ($failed as $name => $pc) {
    error_log("Failed to find giftaid for $name $pc");
}