<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:');

$ratings = json_decode(file_get_contents($opts['f']), TRUE);

error_log("Got " . count($ratings));

foreach ($ratings['ratings'] as $rating) {
    $dbhm->preExec("INSERT IGNORE INTO ratings (ratee, rating, timestamp, visible, tn_rating_id) VALUES (?, ?, ?, ?, ?);", [
        $rating['ratee_fd_user_id'],
        $rating['rating'],
        $rating['date'],
        1,
        $rating['rating_id']
    ]);
}