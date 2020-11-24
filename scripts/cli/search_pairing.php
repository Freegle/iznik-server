<?php

namespace Freegle\Iznik;

# Using https://github.com/nicolasdugue/DirectedLouvain.

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('m:i:o:w:');

$mode = $opts['m'];

if ($mode == 'export') {
    $terms = $dbhr->preQuery("SELECT GREATEST(item1, item2) AS t1, LEAST(item1, item2) as t2, COUNT(*) AS count FROM microactions WHERE item1 IS NOT NULL AND version = 3 GROUP BY t1, t2 ORDER BY count DESC;");
    $renumber = [];
    $nh = fopen($opts['o'], 'w');
    $wh = fopen($opts['w'], 'w');

    foreach ($terms as $term) {
        $t1 = array_search($term['t1'], $renumber, TRUE);

        if ($t1 === FALSE) {
            $renumber[] = $term['t1'];
            $t1 = count($renumber);
        }

        $t2 = array_search($term['t2'], $renumber, TRUE);

        if ($t2 === FALSE) {
            $renumber[] = $term['t2'];
            $t2 = count($renumber);
        }

        fputs($nh, "$t1 $t2 {$term['count']}\n");
    }

    for ($i = 0; $i < count($renumber); $i++) {
        fputs($wh, "{$renumber[$i]} $i\n");
    }
} else {
    $fh = fopen($opts['w'],'r');
    $renumber = [];

    while ($fields = fgetcsv($fh, 0, ' ')){
        $renumber[$fields[1]] = $fields[0];
        #error_log("Renumbered {$fields[1]} = {$fields[0]}");
    }

    $fh = fopen($opts['i'],'r');
    $communities = [];

    while ($fields = fgetcsv($fh, 0, ' ')){
        #error_log("Renumbered {$fields[0]} comm {$fields[1]}");
        if (array_key_exists($fields[0], $renumber)) {
            $terms = $dbhr->preQuery("SELECT * FROM items WHERE id = ?;", [
                $renumber[$fields[0] . '']
            ]);

            foreach ($terms as $term) {
                $communities[$fields[1]][] = $term['name'];
            }
        }
    }

    foreach ($communities as $community) {
        foreach ($community as $term) {
            echo $term . ", ";
        }

        echo "\n";
    }
}
