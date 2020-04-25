<?php

const CLEAN = FALSE;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');


$opts = getopt('f:');

$handle = fopen($opts['f'], "r");
$count = 0;
$wordlist = [];

function addWords($table, $str) {
    global $dbhm, $wordlist;

    $words = explode(' ', strtolower($str));

    foreach ($words as $word) {
        if (pres($word, $wordlist)) {
            $wordlist[$word]++;
        } else {
            $wordlist[$word] = 1;
        }
    }
}

$count = 0;

do {
    $csv = fgetcsv($handle);

    if ($csv) {
        $viafid = $csv[0];
        $author = $csv[1];
        $title = $csv[2];

        $p = strpos($author, ',');
        $q = strpos($author, ',', $p + 1);

        if ($q != FALSE) {
            # Extra info, e.g. dates - remove.
            $author = trim(substr($author, 0, $q));
        }

        if (preg_match('/(.*?)\(/', $author, $matches)) {
            # Extra info - remove.
            $author = trim($matches[1]);
        }

        if ($p !== FALSE) {
            $author = trim(substr($author, $p + 1)) . " " . trim(substr($author, 0, $p));
        }

        #addWords('booktastic_words', $title);
        addWords('booktastic_names', $author);

        $count++;
        if ($count % 1000 == 0) {
            error_log("...$count, " . count($wordlist));
        }
    }
} while ($csv);

$count = 0;

foreach ($wordlist as $word => $freq) {
    $dbhm->preExec("INSERT INTO booktastic_names (word, frequency) VALUES (?, ?) ON DUPLICATE KEY UPDATE frequency = ?;", [
        $word,
        $freq,
        $freq
    ]);

    $count++;
    if ($count % 1000 == 0) {
        error_log("...$count / " . count($wordlist));
    }
}

