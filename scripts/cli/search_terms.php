<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$start = date('Y-m-d', strtotime("365 days ago"));
$terms = $dbhr->preQuery("SELECT DISTINCT(LOWER(term)) AS term, COUNT(*) AS count FROM `search_history` WHERE date >= ? GROUP BY term;", [
    $start
]);

foreach ($terms as $term) {
    error_log("{$term['term']}");
    $dbhm->preExec("REPLACE INTO search_terms (term, count) VALUES (?, ?);", [
        $term['term'],
        $term['count']
    ]);
}