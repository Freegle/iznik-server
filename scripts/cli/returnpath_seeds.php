<?php
# Create users for the emails in our Return Path seed list.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$seeds = $dbhr->preQuery("SELECT * FROM returnpath_seedlist;");

$u = new User($dbhr, $dbhm);

foreach ($seeds as $seed) {
    $uid = $u->findByEmail($seed['email']);
    if (!$uid) {
        $uid = $u->create("ReturnPath", "Seed", NULL);
        error_log("...created {$seed['email']} as $uid");
        $u->addEmail($seed['email']);
        $dbhm->preExec("UPDATE returnpath_seedlist SET userid = ? WHERE id = ?;", [
            $uid,
            $seed['id']
        ]);
    } else {
        error_log("...found {$seed['email']} as $uid");
    }
}