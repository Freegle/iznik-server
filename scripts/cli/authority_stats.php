<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once IZNIK_BASE . '/include/misc/Authority.php';
require_once IZNIK_BASE . '/include/misc/Stats.php';
require_once IZNIK_BASE . '/include/misc/Shortlink.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$opts = getopt('i:o:');

$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
$spreadsheet = $reader->load("authority_stats.xlsx");

if (count($opts) < 1) {
    echo "Usage: php authority_stats -i <authority IDs in a CSL>\n";
} else {
    $ids = explode(',', $opts['i']);
    $q = ceil(date("n") / 3);

    # Read the template

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

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', "Freegle in " . $atts['name']);
        $sheet->setCellValue('A9', $atts['name']);

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
        $sheet->setCellValue('B8', $months[0]['formatted']);
        $sheet->setCellValue('C8', $months[1]['formatted']);
        $sheet->setCellValue('D8', $months[2]['formatted']);
        
        $op .= sprintf("Membership, %d, %d, %d, %d\n", $months[0][Stats::APPROVED_MEMBER_COUNT], $months[1][Stats::APPROVED_MEMBER_COUNT], $months[2][Stats::APPROVED_MEMBER_COUNT], $months[2][Stats::APPROVED_MEMBER_COUNT]);
        $sheet->setCellValue('B10', $months[0][Stats::APPROVED_MEMBER_COUNT]);
        $sheet->setCellValue('C10', $months[1][Stats::APPROVED_MEMBER_COUNT]);
        $sheet->setCellValue('D10', $months[2][Stats::APPROVED_MEMBER_COUNT]);
        $sheet->setCellValue('E10', $months[2][Stats::APPROVED_MEMBER_COUNT]);

        $op .= sprintf("Kgs reused, %d, %d, %d, %d\n", $months[0][Stats::WEIGHT], $months[1][Stats::WEIGHT], $months[2][Stats::WEIGHT], $months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]);
        $sheet->setCellValue('B11', $months[0][Stats::WEIGHT]);
        $sheet->setCellValue('C11', $months[1][Stats::WEIGHT]);
        $sheet->setCellValue('D11', $months[2][Stats::WEIGHT]);
        $sheet->setCellValue('E11', $months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]);
        
        $op .= sprintf("CO2 saved (tonnes), %d, %d, %d, %d\n", round($months[0][Stats::WEIGHT] * 0.51 / 100) / 10, round($months[1][Stats::WEIGHT] * 0.51 / 100) / 10, round($months[2][Stats::WEIGHT] * 0.51 / 100) / 10, round(($months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]) * 0.51 / 100) / 10);
        $sheet->setCellValue('B12', round($months[0][Stats::WEIGHT] * 0.51 / 100) / 10);
        $sheet->setCellValue('C12', round($months[1][Stats::WEIGHT] * 0.51 / 100) / 10);
        $sheet->setCellValue('D12', round($months[2][Stats::WEIGHT] * 0.51 / 100) / 10);
        $sheet->setCellValue('E12', round(($months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]) * 0.51 / 100) / 10);
        
        $op .= sprintf("Benefit (GBP), %d, %d, %d, %d\n", round($months[0][Stats::WEIGHT] * 711 / 100) / 10, round($months[1][Stats::WEIGHT] * 711 / 100) / 10, round($months[2][Stats::WEIGHT] * 711 / 100) / 10, round(($months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]) * 711 / 100) / 10);
        $sheet->setCellValue('B13', round($months[0][Stats::WEIGHT] * 711 / 100) / 10);
        $sheet->setCellValue('C13', round($months[1][Stats::WEIGHT] * 711 / 100) / 10);
        $sheet->setCellValue('D13', round($months[2][Stats::WEIGHT] * 711 / 100) / 10);
        $sheet->setCellValue('E13', round(($months[0][Stats::WEIGHT] + $months[1][Stats::WEIGHT] + $months[2][Stats::WEIGHT]) * 711 / 100) / 10);

        $op .= sprintf("Number of gifts made, %d, %d, %d, %d\n", $months[0][Stats::OUTCOMES], $months[1][Stats::OUTCOMES], $months[2][Stats::OUTCOMES], $months[0][Stats::OUTCOMES] + $months[1][Stats::OUTCOMES] + $months[2][Stats::OUTCOMES]);
        $sheet->setCellValue('B14', round($months[0][Stats::OUTCOMES]));
        $sheet->setCellValue('C14', round($months[1][Stats::OUTCOMES]));
        $sheet->setCellValue('D14', round($months[2][Stats::OUTCOMES]));
        $sheet->setCellValue('E14', round($months[0][Stats::OUTCOMES] + $months[1][Stats::OUTCOMES] + $months[2][Stats::OUTCOMES]));

        $grouprow = 20;

        $links = [];
        
        foreach ($groups as $group) {
            if ($group['overlap'] >= 0.05) {
                $gid = $group['id'];
                $sheet->setCellValue("A$grouprow", $group['namedisplay'] . ($group['overlap'] < 1 ? " *" : ''));

                $sheet->setCellValue("B$grouprow", round($months[0][$gid][Stats::APPROVED_MEMBER_COUNT]));
                $sheet->setCellValue("C$grouprow", round($months[1][$gid][Stats::APPROVED_MEMBER_COUNT]));
                $sheet->setCellValue("D$grouprow", round($months[2][$gid][Stats::APPROVED_MEMBER_COUNT]));
                $sheet->setCellValue("E$grouprow", round($months[2][$gid][Stats::APPROVED_MEMBER_COUNT]));

                $sheet->setCellValue("F$grouprow", round($months[0][$gid][Stats::WEIGHT]));
                $sheet->setCellValue("G$grouprow", round($months[1][$gid][Stats::WEIGHT]));
                $sheet->setCellValue("H$grouprow", round($months[2][$gid][Stats::WEIGHT]));
                $sheet->setCellValue("I$grouprow", round($months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT]));

                $sheet->setCellValue("J$grouprow", round($months[0][$gid][Stats::WEIGHT] * 0.51 / 100) / 10);
                $sheet->setCellValue("K$grouprow", round($months[1][$gid][Stats::WEIGHT] * 0.51 / 100) / 10);
                $sheet->setCellValue("L$grouprow", round($months[2][$gid][Stats::WEIGHT] * 0.51 / 100) / 10);
                $sheet->setCellValue("M$grouprow", round(($months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT]) * 0.51 / 100) / 10);

                $sheet->setCellValue("N$grouprow", round($months[0][$gid][Stats::WEIGHT] * 711 / 100) / 10);
                $sheet->setCellValue("O$grouprow", round($months[1][$gid][Stats::WEIGHT] * 711 / 100) / 10);
                $sheet->setCellValue("P$grouprow", round($months[2][$gid][Stats::WEIGHT] * 711 / 100) / 10);
                $sheet->setCellValue("Q$grouprow", round(($months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT]) * 711 / 100) / 10);

                $sheet->setCellValue("R$grouprow", round($months[0][$gid][Stats::OUTCOMES]));
                $sheet->setCellValue("S$grouprow", round($months[1][$gid][Stats::OUTCOMES]));
                $sheet->setCellValue("T$grouprow", round($months[2][$gid][Stats::OUTCOMES]));
                $sheet->setCellValue("U$grouprow", round($months[0][$gid][Stats::OUTCOMES] + $months[1][$gid][Stats::OUTCOMES] + $months[2][$gid][Stats::OUTCOMES]));

                $grouprow++;

                $sheet->insertNewRowBefore($grouprow, 1);

                $op .= sprintf("%s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d\n",
                               $group['namedisplay'] . ($group['overlap'] < 1 ? ' *' : ''),
                               round($months[0][$gid][Stats::APPROVED_MEMBER_COUNT]), round($months[1][$gid][Stats::APPROVED_MEMBER_COUNT]), round($months[2][$gid][Stats::APPROVED_MEMBER_COUNT]), round($months[2][$gid][Stats::APPROVED_MEMBER_COUNT]),
                               round($months[0][$gid][Stats::WEIGHT]), round($months[1][$gid][Stats::WEIGHT]), round($months[2][$gid][Stats::WEIGHT]), round($months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT]),
                               round($months[0][$gid][Stats::WEIGHT] * 0.51 / 100) / 10, round($months[1][$gid][Stats::WEIGHT] * 0.51 / 100) / 10, round($months[2][$gid][Stats::WEIGHT] * 0.51 / 100) / 10, round(($months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT]) * 0.51 / 100) / 10,
                               round($months[0][$gid][Stats::WEIGHT] * 711 / 100) / 10, round($months[1][$gid][Stats::WEIGHT] * 711 / 100) / 10, round($months[2][$gid][Stats::WEIGHT] * 711 / 100) / 10, round(($months[0][$gid][Stats::WEIGHT] + $months[1][$gid][Stats::WEIGHT] + $months[2][$gid][Stats::WEIGHT]) * 711 / 100) / 10,
                               round($months[0][$gid][Stats::OUTCOMES]), round($months[1][$gid][Stats::OUTCOMES]), round($months[2][$gid][Stats::OUTCOMES]), round($months[0][$gid][Stats::OUTCOMES] + $months[1][$gid][Stats::OUTCOMES] + $months[2][$gid][Stats::OUTCOMES])
                );

                $s = new Shortlink($dbhr, $dbhm);
                $ids = $s->listAll($group['id']);
                foreach ($ids as $id) {
                    $s = new Shortlink($dbhr, $dbhm, $id['id']);
                    $satts = $s->getPublic();

                    $thisone = [
                        'id' => $id['id'],
                        'name' => $id['name'],
                        'clicks' => []
                    ];

                    for ($i = 0; $i < 3; $i++) {
                        $count = 0;

                        foreach ($satts['clickhistory'] as $hist) {
                            if ($hist['date'] >= $months[$i]['start'] && $hist['date'] <= $months[$i]['end']) {
                                $count++;
                            }
                        }

                        $thisone['clicks'][$i] = $count;
                    }

                    $links[] = $thisone;
                }
            }
        }

        error_log($op);

        # Get shortlinks.
        usort($links, function ($a, $b) {
            return (strcmp(strtolower($a['name']), strtolower($b['name'])));
        });

        $shortlinkrow = $grouprow + 5;

        $sheet->setCellValue("B$shortlinkrow", $months[0]['formatted']);
        $sheet->setCellValue("C$shortlinkrow", $months[1]['formatted']);
        $sheet->setCellValue("D$shortlinkrow", $months[2]['formatted']);

        $shortlinkrow += 5;

        foreach ($links as $link) {
            $sheet->setCellValue("A$shortlinkrow", $link['name']);
            $sheet->setCellValue("B$shortlinkrow", $link['clicks'][0]);
            $sheet->setCellValue("C$shortlinkrow", $link['clicks'][1]);
            $sheet->setCellValue("D$shortlinkrow", $link['clicks'][2]);

            $shortlinkrow++;
            $sheet->insertNewRowBefore($shortlinkrow, 1);
        }

        $sheet->setCellValue("A" . ($shortlinkrow + 1), "All data correct at " . date('d/m/Y'));

        # Write the output XLSX.
        $ofn = "/tmp/Freegle-Statistics-{$atts['name']}-" . date('Y') . "-Q$q.xlsx";
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($ofn);
    }
}