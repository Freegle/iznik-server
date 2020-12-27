<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$wordlist = [];

$roads = $dbhr->preQuery("SELECT thoroughfaredescriptor FROM paf_thoroughfaredescriptor");

foreach ($roads as $road) {
    $words = explode(' ', strtolower($road['thoroughfaredescriptor']));

    foreach ($words as $word) {
        if (array_key_exists($word, $wordlist)) {
            $wordlist[$word]++;
        } else {
            $wordlist[$word] = 1;
        }
    }
}

arsort($wordlist);

foreach ($wordlist as $word => $count) {
    error_log("$word $count");
}