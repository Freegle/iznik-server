<?php
# Create users for the emails in our Return Path seed list.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

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
        $dbhm->preExec("REPLACE INTO returnpath_seedlist (type, active, oneshot, email, userid) VALUES ('Litmus', 1, 1, ?, ?);", [
            $email,
            $uid
        ]);
    }

    error_log($email);
}

do {
    $count = $dbhm->preQuery("SELECT COUNT(*) AS count FROM returnpath_seedlist WHERE type = 'Litmus' AND active = 1;");
    error_log("Active seeds: " . $count[0]['count']);
    sleep(1);
} while ($count[0]['count'] > 0);
