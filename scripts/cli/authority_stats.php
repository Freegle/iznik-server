<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once IZNIK_BASE . '/include/misc/Authority.php';
require_once IZNIK_BASE . '/include/misc/Stats.php';

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php authority_stats -i <authority IDs in a CSL>\n";
} else {
    $ids = explode(',', $opts['i']);

    $s = new Stats($dbhr, $dbhm);
    $stats = $s->getByAuthority($ids);

    echo "PartialPostcode, Offers, Wanteds, Searches, Total weight of exchanges (kg)\n";

    foreach ($stats as $pc => $stat) {
        echo "$pc, {$stat[Message::TYPE_OFFER]}, {$stat[Message::TYPE_WANTED]}, {$stat[Stats::SEARCHES]}, " . round($stat[Stats::WEIGHT], 1) . "\n";
    }
}