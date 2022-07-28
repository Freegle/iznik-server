<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

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
                if ($rating['rating']) {
                    $dbhm->preExec("INSERT INTO ratings (ratee, rating, timestamp, visible, tn_rating_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = ?, timestamp = ?;", [
                        $rating['ratee_fd_user_id'],
                        $rating['rating'],
                        $rating['date'],
                        1,
                        $rating['rating_id'],
                        $rating['rating'],
                        $rating['date']
                    ]);
                } else {
                    $dbhm->preExec("DELETE FROM ratings WHERE ratee = ? AND tn_rating_id = ?;", [
                        $rating['ratee_fd_user_id'],
                        $rating['rating_id']
                    ]);
                }
            } catch (\Exception $e) {
                error_log("Ratings sync failed " . $e->getMessage() . " " . var_export($rating, TRUE));
                \Sentry\captureException($e);
            }
        }
    }
} while (count($ratings) == 100);

# Sync the reply time and about me.
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
                        \Sentry\captureException($e);
                    }
                }

                # Spot name changes.
                $u = User::get($dbhr, $dbhm, $change['fd_user_id']);

                $oldname = User::removeTNGroup($u->getName());

                if ($oldname != $change['username']) {
                    error_log("Name change for {$change['fd_user_id']} $oldname => {$change['username']}");
                    $u->setPrivate('fullname', $change['username']);
                }
            } catch (\Exception $e) {
                error_log("Ratings sync failed " . $e->getMessage() . " " . var_export($rating, true));
                \Sentry\captureException($e);
            }
        }
    }
} while (count($changes) == 100);

# Spot any duplicate FD users we have created for TN users.  This should no longer happen given the locking code in
# Message::parse and so could be retired once we're convinced it is fixed.
$users = $dbhr->preQuery("SELECT COUNT(DISTINCT(userid)) AS count, REGEXP_REPLACE(email, '(.*)-g[0-9]+@user\.trashnothing\.com', '$1') AS username FROM users_emails WHERE email LIKE '%@user.trashnothing.com' GROUP BY username HAVING count > 1;");

if (count($users) > 0) {
    error_log("Found " . count($users) . " duplicate TN users");
    $u = User::get($dbhr, $dbhm);

    foreach ($users as $user) {
        error_log("Look for dups for {$user['username']}");
        $userids = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users_emails WHERE REGEXP_REPLACE(email, '(.*)-g[0-9]+@user\.trashnothing\.com', '$1') = ? AND email LIKE '%@user.trashnothing.com';", [
            $user['username']]
        );

        error_log("Found " . count($userids) . " users for {$user['username']}");
        if (count($userids) > 1) {
            $mergeto = $userids[0]['userid'];

            foreach ($userids as $userid) {
                if ($userid['userid'] != $mergeto) {
                    error_log("Merging {$userid['userid']} into $mergeto");
                    $u->merge($mergeto, $userid['userid'], "Duplicate TN user created accidentally");
                }
            }
        }
    }
}


Utils::unlockScript($lockh);