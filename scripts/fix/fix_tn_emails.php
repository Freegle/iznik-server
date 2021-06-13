<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$fh = fopen('/tmp/tnemails-to-tnuserids.csv', 'r');

$tnemails = [];

if ($fh) {
    while (!feof($fh)) {
        $fields = fgetcsv($fh);

        if ($fields[1] != 'null') {
            $tnemails[$fields[0]] = $fields[1];
        }
    }
}

$uids = $dbhr->preQuery("SELECT DISTINCT userid FROM users_emails WHERE email LIKE '%trashnothing.com'");
$total = count($uids);
$count = 0;

foreach ($uids as $uid) {
    $names = [];

    $emails = $dbhr->preQuery("SELECT email FROM users_emails WHERE userid = ? AND email LIKE '%trashnothing.com';", [
        $uid['userid']
    ]);

    foreach ($emails as $email) {
        $p = strpos($email['email'], '-');

        if ($p != -1) {
            $name = substr($email['email'], 0, $p);

            if (strlen($name)) {
                $names[$name] = TRUE;
            }
        }
    }

    if (count(array_keys($names)) > 1) {
        #echo("{$uid['userid']}," . implode(',', array_keys($names)) . "\n");
        $tnid = NULL;

        foreach ($names as $name => $count2) {
            if (array_key_exists($name, $tnemails)) {
                if ($tnid && $tnid != $tnemails[$name]) {
                    $u = new User($dbhr, $dbhm, $uid['userid']);
                    error_log("{$uid['userid']} " . implode(',', array_keys($names)) . " has multiple TN ids $tnid, {$tnemails[$name]}, last access " . $u->getPrivate('lastaccess'));

                    foreach ($names as $name2 => $nowt) {
                        error_log("...process $name2 => $nowt");
                        $qname = str_replace('_', '\_', $name2);
                        $tnemails2 = $dbhr->preQuery("SELECT * FROM users_emails WHERE userid = ? AND email LIKE '$qname-%';", [
                            $uid['userid']
                        ]);

                        error_log("..." . count($tnemails2) . " emails");

                        $splituid = NULL;

                        foreach ($tnemails2 as $tnemail) {
                            if (!$splituid) {
                                error_log("Split out {$tnemail['email']}");
                                $usplit = new User($dbhr, $dbhm, $uid['userid']);
                                $splituid = $usplit->split($tnemail['email']);
                                $membs = $usplit->getMembershipGroupIds();
                                $usplitted = new User($dbhr, $dbhm, $splituid);
                                foreach ($membs as $memb) {
                                    $usplitted->addMembership($memb);
                                    error_log("Add in to group $memb");
                                }
                                error_log("...into $splituid");
                            } else {
                                error_log("...move {$tnemail['email']} across");
                                $usplit2 = new User($dbhr, $dbhm, $uid['userid']);
                                $splituid2 = $usplit->split($tnemail['email']);
                                $usplit2->merge($splituid, $splituid2, 'Demerging TN users', TRUE);
                            }
                        }
                    }

                    error_log("...processed all names");
                } else {
                    $tnid = $tnemails[$name];
                }
            }
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count / $total");
        gc_collect_cycles();
    }
}