<?php
# Create users for the emails in our Return Path seed list.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$emails = $argv;
array_shift($emails);
$u = new User($dbhr, $dbhm);

foreach ($emails as $email) {
    $email = str_replace(',', '', $email);

    # Add each one into the seed table, as a one-shot and active.  Create users if need be,
    $uid = $u->findByEmail($email);
    if (!$uid) {
        # We don't have it.
        $uid = $u->create("Litmus", "Seed", NULL);
        error_log("...created $email as $uid");
        $u->addEmail($email);

        $dbhm->preExec("INSERT INTO returnpath_seedlist (email, userid, active, oneshot, type) VALUES (?, ?, 1, 1, 'Litmus');", [
            $email,
            $uid,
        ]);
    } else {
        # We already know this one; reset it so that we send.
        error_log("...found $email as $uid");
        $dbhm->preExec("UPDATE returnpath_seedlist SET type = 'Litmus', active = 1, oneshot = 1 WHERE email LIKE ?;", [
            $email
        ]);
    }

    error_log($email);
}