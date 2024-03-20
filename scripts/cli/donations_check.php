<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$fh = fopen('/tmp/all.csv', 'r');

$count = 0;
$total = 0;
$uids = [];

while (!feof($fh))
{
    $fields = fgetcsv($fh);
    $email = $fields[2];
    $u = new User($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if ($uid) {
        $uids[] = $uid;

        $donations = $dbhr->preQuery("SELECT SUM(GrossAmount) AS total FROM `users_donations` WHERE (userid = ? OR Payer = ?) AND timestamp >= '2024-02-01' AND timestamp <= '2024-03-01';", [
            $uid,
            $email
        ]);

        if ($donations[0]['total'] > 0) {
            error_log("$uid $email donated {$donations[0]['total']}");
            $count++;
            $total += $donations[0]['total'];
        }
    }
}

error_log("\n\n$count donors donated $total\n\n");

$start = '2024-02-15';
$end = '2024-03-15';

$users = $dbhr->preQuery("SELECT id FROM users WHERE id NOT IN (" . implode(',', $uids) . ") AND added < '$end' AND added >= '$start' AND marketingconsent = 1 ORDER by added DESC LIMIT " . count($uids) . ";", [
]);

error_log("Found other users " . count($users));

$count = 0;
$total = 0;
foreach ($users as $user) {
    $uid = $user['id'];
    $u = new User($dbhr, $dbhm, $uid);
    $email = $u->getEmailPreferred();

    if ($uid) {
        $donations = $dbhr->preQuery("SELECT SUM(GrossAmount) AS total FROM `users_donations` WHERE (userid = ? OR Payer = ?) AND timestamp >= '$start' AND timestamp < '$end';", [
            $uid,
            $email
        ]);

        if ($donations[0]['total'] > 0) {
            error_log("$uid $email donated {$donations[0]['total']}");
            $count++;
            $total += $donations[0]['total'];
        }
    }
}

error_log("\n\n$count other donors donated $total\n\n");
