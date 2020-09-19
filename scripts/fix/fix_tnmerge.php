<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
require_once dirname(__FILE__) . '/../../include/config.php';

require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

error_log("Query");
$users = $dbhr->preQuery("SELECT email, userid FROM users_emails WHERE userid IN (SELECT userid FROM users_emails WHERE email LIKE '%trashnothing%' AND LOCATE('-', email) > 0 GROUP BY userid HAVING COUNT(DISTINCT(SUBSTR(email, 1, LOCATE('-', email)))) > 1) ORDER BY userid;");
$count = count($users);
error_log("Got $count");

$index = [];

foreach ($users as $user) {
    if (!Utils::pres($user['userid'], $index)) {
        $index[$user['userid']] = [];
    }

    $index[$user['userid']][] = $user['email'];
}

foreach ($index as $userid => $emails) {
    error_log("$userid: " . implode(',', $emails));
}
