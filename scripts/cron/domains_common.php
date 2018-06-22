<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

$emails = $dbhr->preQuery("SELECT email FROM `users_emails`;");
$total = count($emails);

error_log("Found $total\n");
$count = 0;
$domains = [];

foreach ($emails as $email) {
    $p = strpos($email['email'], '@');

    if ($p > 0) {
        $domain = substr($email['email'], $p + 1);
        if (array_key_exists($domain, $domains)) {
            $domains[$domain]++;
        } else {
            $domains[$domain] = 1;
        }
    }

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}

error_log("Got " . count($domains) . " domains");

foreach ($domains as $domain => $count) {
    if ($count > 1000) {
        $dbhm->preExec("REPLACE INTO domains_common (count, domain) VALUES (?, ?);", [
            $count,
            $domain
        ]);
    }
}