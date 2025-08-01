<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$donesummat = TRUE;

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
        $donesummat = TRUE;

        if ($rating['ratee_fd_user_id']) {
            // TN id might be wrong - check the user exists.
            $u = User::get($dbhr, $dbhm, $rating['ratee_fd_user_id']);

            if ($u->getId()) {
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
    }
} while ($ratings && count($ratings) == 100);

# Sync the reply time and about me.
$page = 1;

do {
    $url = "https://trashnothing.com/fd/api/user-changes?key=" . TNKEY . "&page=$page&per_page=100&date_min=$from&date_max=$to";
    $changes = json_decode(file_get_contents($url), TRUE)['changes'];
    $page++;

    foreach ($changes as $change) {
        $donesummat = TRUE;

        if ($change['fd_user_id']) {
            try {
                $u = User::get($dbhr, $dbhm, $change['fd_user_id']);

                if ($u->isTN()) {
                    if (Utils::pres('account_removed', $change)) {
                        error_log("FD #{$change['fd_user_id']} TN account removed");
                        $u->forget('TN account removed');
                    } else {
                        if (Utils::pres('reply_time', $change)) {
                            $dbhm->preExec(
                                "REPLACE INTO users_replytime (userid, replytime, timestamp) VALUES (?, ?, ?);",
                                [
                                    $change['fd_user_id'],
                                    $change['reply_time'],
                                    $change['date']
                                ]
                            );
                        }

                        if (Utils::pres('about_me', $change)) {
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
                        $oldname = User::removeTNGroup($u->getName());

                        if (Utils::pres('username', $change) && $oldname != $change['username']) {
                            error_log("Name change for {$change['fd_user_id']} $oldname => {$change['username']}");
                            $u->setPrivate('fullname', $change['username']);

                            $emails = $u->getEmails();

                            foreach ($emails as $email) {
                                if (strpos($email['email'], "$oldname-") !== FALSE) {
                                    $u->removeEmail($email['email']);
                                    error_log("...{$email['email']} => " . str_replace("$oldname-", "{$change['username']}-", $email['email']));
                                    $u->addEmail(str_replace("$oldname-", "{$change['username']}-", $email['email']));
                                }
                            }
                        }

                        if (Utils::pres('location', $change)) {
                            $lat = Utils::presdef('latitude', $change['location'], NULL);
                            $lng = Utils::presdef('longitude', $change['location'], NULL);

                            if ($lat !== NULL && $lng !== NULL) {
                                #error_log("FD #{$change['fd_user_id']} TN lat/lng $lat,$lng");
                                $l = new Location($dbhr, $dbhm);

                                $loc = $l->closestPostcode($lat, $lng);

                                if ($loc) {
                                    #error_log("...found postcode {$loc['id']} {$loc['name']}");

                                    if ($loc['id'] !== $u->getPrivate('locationid')) {
                                        error_log("FD #{$change['fd_user_id']} TN lat/lng $lat,$lng has changed {$u->getPrivate('locationid')} => {$loc['id']} {$loc['name']}");
                                        $u->setPrivate('lastlocation', $loc['id']);
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("Ratings sync failed " . $e->getMessage() . " " . var_export($rating, true));
                \Sentry\captureException($e);
            }
        }
    }
} while ($changes && count($changes) == 100);

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

if (!$donesummat) {
    \Sentry\CaptureMessage("TN sync did nothing");
}

Utils::unlockScript($lockh);