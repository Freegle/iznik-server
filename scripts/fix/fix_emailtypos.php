<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$emails = $dbhr->preQuery("SELECT email, userid FROM `users_emails` INNER JOIN users ON users_emails.userid = users.id WHERE bouncing = 1;");
$total = count($emails);

error_log("Found $total\n");
$count = 0;
$domains = [];

$exists = 0;
$nonexists = 0;

foreach ($emails as $email) {
    $p = strpos($email['email'], '@');

    if ($p > 0) {
        $domain = substr($email['email'], $p + 1);

        $domains = $dbhr->preQuery("SELECT id FROM domains_common WHERE domain LIKE ?;", [
            $domain
        ]);

        if (count($domains) === 0) {
            # This is not a common domain.  It may be a typo.  See if there are suggestions we can make.,
            $sql = "SELECT * FROM domains_common WHERE damlevlim(`domain`, ?, " . strlen($domain) . ") < 3 ORDER BY count DESC LIMIT 1;";
            $suggestions = $dbhr->preQuery($sql, [ $domain ], FALSE, FALSE);

            foreach ($suggestions as $suggestion) {
                $newemail = substr($email['email'], 0, $p + 1) . $suggestion['domain'];
                error_log("Consider {$email['email']} => $newemail");
                $existing = $dbhr->preQuery("SELECT * FROM users_emails WHERE email LIKE ?;", [
                    $newemail
                ], FALSE, FALSE);

                $u = new User($dbhr, $dbhm, $email['userid']);

                if (count($existing) == 0) {
                    error_log("...doesn't exist, correct");
                    $u->removeEmail($email['email']);
                    $u->addEmail($newemail);
                    $nonexists++;
                } else {
                    error_log("...already exists, delete old");
                    $u->delete();
                    $exists++;
                }
            }
        }
    }

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}

error_log("\n\nExists $exists non-exists $nonexists");

