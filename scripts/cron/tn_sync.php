<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Find the latest TN rating we have - that's what we use to decide the time period for the sync.
$latest = $dbhr->preQuery("SELECT MAX(timestamp) AS max FROM `ratings` WHERE tn_rating_id IS NOT NULL;");
$from = Utils::ISODate('@' . strtotime($latest[0]['max']));
$to = Utils::ISODate('@' . time());

# Sync the ratings.
$page = 1;

do {
    $url = "https://trashnothing.com/fd/api/ratings?key=" . TNKEY . "&page=$page&per_page=100&date_min=$from&date_max=$to";
    $ratings = json_decode(file_get_contents($url), TRUE)['ratings'];
    $page++;

    foreach ($ratings as $rating) {
        if ($rating['ratee_fd_user_id']) {
            try {
                $dbhm->preExec("INSERT INTO ratings (ratee, rating, timestamp, visible, tn_rating_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = ?, timestamp = ?;", [
                    $rating['ratee_fd_user_id'],
                    $rating['rating'],
                    $rating['date'],
                    1,
                    $rating['rating_id'],
                    $rating['rating'],
                    $rating['date']
                ]);
            } catch (\Exception $e) {
                error_log("Ratings sync failed " . $e->getMessage() . " " . var_export($rating, TRUE));
            }
        }
    }
} while (count($ratings) == 100);

# Sync the reply time and about me/.
$page = 1;

do {
    $url = "https://trashnothing.com/fd/api/user-changes?key=" . TNKEY . "&page=$page&per_page=100&date_min=$from&date_max=$to";
    $changes = json_decode(file_get_contents($url), TRUE)['changes'];
    $page++;

    foreach ($changes as $change) {
        if ($change['fd_user_id']) {
            try {
                $dbhm->preExec(
                    "REPLACE INTO users_replytime (userid, replytime, timestamp) VALUES (?, ?, ?);",
                    [
                        $change['fd_user_id'],
                        $change['reply_time'],
                        $change['date']
                    ]
                );

                if ($change['about_me']) {
                    try {
                        $dbhm->preExec(
                            "REPLACE INTO users_aboutme (userid, timestamp, text) VALUES (?, ?, ?);",
                            [
                                $change['fd_user_id'],
                                $change['date'],
                                $change['about_me']
                            ]
                        );
                    } catch (\Exception $e) {
                    }
                }
            } catch (\Exception $e) {
                error_log("Ratings sync failed " . $e->getMessage() . " " . var_export($rating, true));
            }
        }
    }
} while (count($changes) == 100);