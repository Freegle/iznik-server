<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:');

# Find the latest TN rating we have.
$latest = $dbhr->preQuery("SELECT MAX(timestamp) AS max FROM `ratings` WHERE tn_rating_id IS NOT NULL;");
$from = Utils::ISODate('@' . strtotime($latest[0]['max']));
$to = Utils::ISODate('@' . time());

$page = 1;

do {
    $url = "https://trashnothing.com/fd/api/ratings?key=" . TNKEY . "&page=$page&per_page=100&date_min=$from&date_max=$to";
    $ratings = json_decode(file_get_contents($url), TRUE)['ratings'];
    $page++;

    foreach ($ratings as $rating) {
        error_log("Add TN rating {$rating['rating']} id {$rating['rating_id']} for {$rating['ratee_fd_user_id']}");
        $dbhm->preExec("INSERT INTO ratings (ratee, rating, timestamp, visible, tn_rating_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = ?, timestamp = ?;", [
            $rating['ratee_fd_user_id'],
            $rating['rating'],
            $rating['date'],
            1,
            $rating['rating_id'],
            $rating['rating'],
            $rating['date']
        ]);
    }
} while (count($ratings) == 100);