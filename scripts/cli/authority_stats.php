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

    $months = [];

    # Find start of last full quarter
    $start_date = strtotime('3 months ago');
    $start_quarter = ceil(date('m', $start_date) / 3);
    $start_month = ($start_quarter * 3) - 2;
    $start_year = date('Y', $start_date);
    $start_timestamp = mktime(0, 0, 0, $start_month, 1, $start_year);

    $stattypes = [
        Stats::APPROVED_MEMBER_COUNT,
        Stats::WEIGHT,
        Stats::OUTCOMES
    ];

    # Get the months in this quarter.
    for ($i = 0; $i < 3; $i++) {
        $end_timestamp = strtotime("+1 month", $start_timestamp);
        $months[] = [
            'start' => date('Y-m-d', $start_timestamp),
            'end' => date('Y-m-d', $end_timestamp),
            'formatted' => date('M-y', $start_timestamp)
        ];

        $start_timestamp = $end_timestamp;
    }

    foreach ($ids as $id) {
        $a = new Authority($dbhr, $dbhm, $id);
        $atts = $a->getPublic();
        $groups = $atts['groups'];
        $gids = array_column($groups, 'id');

        for ($month = 0; $month < 3; $month++) {
            $months[$month]['groups'] = [];
            foreach ($stattypes as $stattype) {
                $months[$month][$stattype] = 0;
            }

            $months[$month][Stats::APPROVED_MEMBER_COUNT] = 0;

            foreach ($groups as $group) {
                $s = new Stats($dbhr, $dbhm);
                $gid = $group['id'];
                $stats = $s->getMulti(NULL, [ $gid ], $months[$month]['start'],  $months[$month]['end'], FALSE, $stattypes);

                foreach ($stattypes as $stattype) {
                    $months[$month][$gid][$stattype] = 0;

                    foreach ($stats[$stattype] as $stat) {
                        if ($stattype == Stats::APPROVED_MEMBER_COUNT) {
                            # We want the last value
                            $months[$month][$gid][$stattype] = $stat['count'] * $group['overlap'];
                        } else {
                            # We want to sum.
                            $months[$month][$gid][$stattype] += $stat['count'] * $group['overlap'];
                            $months[$month][$stattype] += $stat['count'] * $group['overlap'];
                        }
                    }
                }

                $months[$month][Stats::APPROVED_MEMBER_COUNT] += $months[$month][$gid][Stats::APPROVED_MEMBER_COUNT];
            }
        }

        # Output results.
        # Benefit of reuse per tonne is £711 and CO2 impact is -0.51tCO2eq based on WRAP figures.
        # http://www.wrap.org.uk/content/monitoring-tools-and-resources
        $op = '';
        $op .= sprintf(", %s, %s, %s, Total\n", $months[0]['formatted'], $months[1]['formatted'], $months[2]['formatted']);
        $op .= sprintf("Membership, %d, %d, %d, %d\n", $months[0][Stats::APPROVED_MEMBER_COUNT], $months[2][Stats::APPROVED_MEMBER_COUNT], $months[2][Stats::APPROVED_MEMBER_COUNT], $months[2][Stats::APPROVED_MEMBER_COUNT]);
        $op .= sprintf("Kgs reused, %d, %d, %d, %d\n", $months[0][Stats::WEIGHT], $months[1][Stats::WEIGHT], $months[2][Stats::WEIGHT], $months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]);
        $op .= sprintf("CO2 saved (tonnes), %d, %d, %d, %d\n", round($months[0][Stats::WEIGHT] * 0.51 / 100) / 10, round($months[1][Stats::WEIGHT] * 0.51 / 100) / 10, round($months[2][Stats::WEIGHT] * 0.51 / 100) / 10, round(($months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]) * 0.51 / 100) / 10);
        $op .= sprintf("Benefit (GBP), %d, %d, %d, %d\n", round($months[0][Stats::WEIGHT] * 711 / 100) / 10, round($months[1][Stats::WEIGHT] * 711 / 100) / 10, round($months[2][Stats::WEIGHT] * 711 / 100) / 10, round(($months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]) * 711 / 100) / 10);
        $op .= sprintf("Number of gifts made, %d, %d, %d, %d\n", $months[0][Stats::OUTCOMES], $months[1][Stats::OUTCOMES], $months[2][Stats::OUTCOMES], $months[0][Stats::OUTCOMES] + $months[1][Stats::OUTCOMES] + $months[2][Stats::OUTCOMES]);
        
        foreach ($groups as $group) {
            $gid = $group['id'];

            $op .= sprintf("%s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d\n",
                $group['namedisplay'] . ($group['overlap'] < 1 ? ' *' : ''),
                $months[0][$gid][Stats::APPROVED_MEMBER_COUNT], $months[2][$gid][Stats::APPROVED_MEMBER_COUNT], $months[2][$gid][Stats::APPROVED_MEMBER_COUNT], $months[2][$gid][Stats::APPROVED_MEMBER_COUNT],
                $months[0][$gid][Stats::WEIGHT], $months[1][$gid][Stats::WEIGHT], $months[2][$gid][Stats::WEIGHT], $months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT],
                round($months[0][$gid][Stats::WEIGHT] * 0.51 / 100) / 10, round($months[1][$gid][Stats::WEIGHT] * 0.51 / 100) / 10, round($months[2][$gid][Stats::WEIGHT] * 0.51 / 100) / 10, round(($months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT]) * 0.51 / 100) / 10,
                round($months[0][$gid][Stats::WEIGHT] * 711 / 100) / 10, round($months[1][$gid][Stats::WEIGHT] * 711 / 100) / 10, round($months[2][$gid][Stats::WEIGHT] * 711 / 100) / 10, round(($months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT]) * 711 / 100) / 10,
                $months[0][$gid][Stats::OUTCOMES], $months[1][$gid][Stats::OUTCOMES], $months[2][$gid][Stats::OUTCOMES], $months[0][$gid][Stats::OUTCOMES] + $months[1][$gid][Stats::OUTCOMES] + $months[2][$gid][Stats::OUTCOMES]
            );
        }

        error_log($op);
    }
}