<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$emails = $dbhr->preQuery("SELECT id, email, userid, added FROM `users_emails` ORDER BY id ASC;");
$total = count($emails);
$count = 0;
$yahoocount = 0;
$membcount = 0;

foreach ($emails as $email) {
    # Check for Yahoo membership date
    $yahoos = $dbhr->preQuery("SELECT added FROM memberships_yahoo WHERE emailid = ? ORDER BY added ASC LIMIT 1;", [
        $email['id']
    ]);

    if (count($yahoos)) {
//        error_log("{$yahoos[0]['added']} vs {$email['added']}");
        if (strtotime($yahoos[0]['added']) < strtotime($email['added'])) {
//            error_log("...{$email['email']} {$yahoos[0]['added']} from Yahoo");
            $dbhm->preExec("UPDATE users_emails SET added = ? WHERE id = ?;", [
                $yahoos[0]['added'],
                $email['id']
            ]);
            $yahoocount++;
        }
    } else {
        # Other memberships
        $membs = $dbhr->preQuery("SELECT added FROM memberships WHERE userid = ? ORDER BY added ASC LIMIT 1;", [
            $email['userid']
        ]);

        if (count($membs)) {
//            error_log("{$membs[0]['added']} vs {$email['added']}");
            if (strtotime($membs[0]['added']) < strtotime($email['added'])) {
//                error_log("...{$email['email']} {$membs[0]['added']} from memberships");
                $dbhm->preExec("UPDATE users_emails SET added = ? WHERE id = ?;", [
                    $membs[0]['added'],
                    $email['id']
                ]);
                $membcount++;
            }
        }
    }

    $count++;

    if ($count % 10000 === 0) {
        error_log("...$count / $total");
    }
}

error_log("From Yahoo $yahoocount from membs $membcount");