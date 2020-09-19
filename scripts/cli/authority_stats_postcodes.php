<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php authority_stats_postcode -i <authority IDs in a CSL>\n";
} else {
    $ids = explode(',', $opts['i']);

    $s = new Stats($dbhr, $dbhm);
    $stats = $s->getByAuthority($ids);

    echo "PartialPostcode, Offers, Wanteds, Searches, Total weight of exchanges (kg)\n";

    foreach ($stats as $pc => $stat) {
        echo "$pc, {$stat[Message::TYPE_OFFER]}, {$stat[Message::TYPE_WANTED]}, {$stat[Stats::SEARCHES]}, " . round($stat[Stats::WEIGHT], 1) . "\n";
    }
}