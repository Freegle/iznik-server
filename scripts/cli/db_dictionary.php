<?php
# Percona has column compression using a dictionary.  This script scans a column in a table to suggest a dictionary
# based on word frequency.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('t:c:');

if (count($opts) < 2) {
    echo "Usage: php db_dictionary.php -t <table> -c <column>";
} else {
    $strings = [];

    $rows = $dbhr->preQuery("SELECT {$opts['c']} FROM {$opts['t']};");

    foreach ($rows as $row) {
        // Keep a count of each word
        $words = preg_split('/\W+/', $row[$opts['c']]);

        foreach ($words as $word) {
            $word = strtolower($word);

            if (strlen($word) > 2) {
                if (array_key_exists($word, $strings)) {
                    $strings[$word]++;
                } else {
                    $strings[$word] = 1;
                }
            }
        }
    }

    // Sort strings by count
    arsort($strings);

    // Output the string and count
    $dict = '';
    foreach ($strings as $string => $count) {
        if ($count > 100 && strlen($dict) < 1024 && strpos($string, "'") === FALSE) {
            $dict .= " '$string'";
        }
    }

    echo "CREATE COMPRESSION_DICTIONARY {$opts['t']}_{$opts['c']}_dict ($dict);\n";
}
