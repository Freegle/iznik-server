<?php

# Send test digest to a specific user
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

# Get 50 most frequent donors
$donors = $dbhr->preQuery("SELECT count(*) as count, userid FROM `users_donations` GROUP BY userid HAVING count >= 20 ORDER BY count DESC LIMIT 500;");
$count = 0;

foreach ($donors as $donor) {
    $u = new User($dbhr, $dbhm, $donor['userid']);

    if (!$u->isModerator()) {
        $last = $dbhr->preQuery("SELECT MAX(timestamp) AS m FROM users_donations WHERE userid = ?;", [
            $donor['userid']
        ]);

        foreach ($last as $l) {
            if (time() - strtotime($l['m']) <= 90 * 24 * 60 * 60) {
                error_log("User #{$donor['userid']} ". $u->getEmailPreferred() . " made {$donor['count']} last {$l['m']}");
                $count++;

                if ($count > 50) {
                    exit(0);
                }
            }
        }
    }
}
