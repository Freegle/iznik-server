<?php

namespace Freegle\Iznik;
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT id, lastlocation, settings FROM users WHERE settings IS NOT NULL;");

error_log(count($users) . " users");

$total = count($users);
$count = 0;

function fixUser($user, $dbhr, $dbhm) {
    $s = json_decode($user['settings'], TRUE);

    if (Utils::pres('mylocation', $s) && $s['mylocation']['id'] != $user['lastlocation']) {
        #error_log("{$user['id']} => {$s['mylocation']['id']}");

        try {
            $dbhm->preExec("UPDATE users SET lastlocation = ? WHERE id = ?;", [
                $s['mylocation']['id'],
                $user['id']
            ], FALSE);
        } catch (\Exception $e) {
            // Likely to be because the location is invalid.
            error_log("User {$user['id']} failed on location #{$s['mylocation']['id']} name {$s['mylocation']['name']}");
            $l = new Location($dbhr, $dbhm);
            $lid = $l->findByName($s['mylocation']['name']);

            if ($lid) {
                error_log("...found as $lid");
                $s['mylocation']['id'] = $lid;
                $dbhm->preQuery("UPDATE users SET settings = ? WHERE id = ?;", [
                    json_encode($s),
                    $user['id']
                ]);
            } else {
                error_log("...some other error " . $e->getMessage());
            }
        }
    }

    gc_collect_cycles();
}

foreach ($users as $user) {
    fixUser($user, $dbhm, $dbhm);

    $count ++;

    if ($count % 1000 == 0) {
        error_log("...$count / $total");
    }
}