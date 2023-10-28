<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Find the latest TN rating we have - that's what we use to decide the time period for the sync.
$day = 0;

for ($day = 0; $day < 20; $day ++) {
    $latest = '2023-10-08';
    $from = Utils::ISODate('@' . (strtotime($latest) + $day * 24 * 60 * 60));
    $to = Utils::ISODate('@' . (strtotime($latest) + ($day + 1) * 24 * 60 * 60));

    $page = 1;

    do {
        $url = "https://trashnothing.com/fd/api/user-changes?key=" . TNKEY . "&page=$page&per_page=100&date_min=$from&date_max=$to";
        error_log($url);
        $changes = json_decode(file_get_contents($url), TRUE)['changes'];
        $page++;

        foreach ($changes as $change) {
            error_log(json_encode($change));
            if ($change['fd_user_id']) {
                try {
                    $u = User::get($dbhr, $dbhm, $change['fd_user_id']);

                    if ($u->isTN()) {
                        if (Utils::pres('account_removed', $change)) {
                            error_log("FD #{$change['fd_user_id']} TN account removed");
                        } else {
                            # Spot name changes.
                            $oldname = User::removeTNGroup($u->getName());

                            if (Utils::pres('username', $change) && $oldname != $change['username']) {
                                error_log("Name change for {$change['fd_user_id']} $oldname => {$change['username']}");
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Ratings sync failed " . $e->getMessage() . " " . var_export($rating, true));
                    \Sentry\captureException($e);
                }
            }
        }
    } while (count($changes) == 100);

    $day++;
}

Utils::unlockScript($lockh);