<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';

require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
global $dbhr, $dbhm;

$maxdate = '2022-08-12';

# First, find any email addresses which refer to TN names which TN itself doesn't know.  These will no longer
# be valid on TN, and we should delete them.  This also means we then only have users with a single TN name
# in their email addresses, which simplifies the next steps.
$tnusers = [];

error_log("Loading tn-new-and-old-usernames");
$fh = fopen('/tmp/tn-new-and-old-usernames.csv', 'r');

while (!feof($fh))
{
    $fields = fgetcsv($fh);
    $tnid = $fields[0];
    $tncurrent = $fields[1];
    $tnold = $fields[2];
    $tnfdid = $fields[3];

    $tnusers[$tnold] = [
        'id' => $tnid,
        'name' => $tncurrent,
        'old' => TRUE
    ];
}

fclose ($fh);

error_log("Loading tn-usernames");
$fh = fopen('/tmp/tn-usernames.csv', 'r');

while (!feof($fh))
{
    $fields = fgetcsv($fh);
    $tnid = $fields[0];
    $tncurrent = $fields[1];
    $tnfdid = $fields[2];

    $tnusers[$tncurrent] = [
        'id' => $tnid,
        'name' => $tncurrent,
        'old' => FALSE,
        'tnfdid' => $tnfdid
    ];
}

fclose($fh);

// Now process each TN email we have to ensure each FD user has emails with a single TN name.
error_log("Find TN emails");
$tnemails = $dbhr->preQuery(
    "SELECT users_emails.*, tnuserid FROM users_emails INNER JOIN users ON users_emails.userid = users.id WHERE email LIKE '%@user.trashnothing.com' AND users_emails.added < ?;",
    [
        $maxdate
    ]
);
$count = 0;
$removed = 0;
$updatedid = 0;
$updatedfailed = 0;
$bademail = 0;
$multiples = 0;

foreach ($tnemails as $tnemail)
{
    $count++;

    if ($count % 1000 === 0)
    {
        error_log("...$count / " . count($tnemails));
    }

    $uid = $tnemail['userid'];
    $tnname = substr($tnemail['email'], 0, strrpos($tnemail['email'], '-g'));

    if (!strlen($tnname)) {
        $bademail++;
        $gotvalid = false;

        $otheremails = $dbhr->preQuery("SELECT email FROM users_emails WHERE userid = ?;", [
            $uid
        ]);

        foreach ($otheremails as $otheremail)
        {
            $tnname2 = substr($otheremail['email'], 0, strrpos($otheremail['email'], '-g'));

            if (strlen($tnname2))
            {
                error_log(
                    "WARNING: ...{$tnemail['email']} invalid format but found valid {$tnname2} for {$otheremail['email']}"
                );
                $gotvalid = true;
                break;
            }
        }

        if (!$gotvalid)
        {
            if (strpos($tnemail['email'], '+g') !== false)
            {
                # Old TN format - convert.
                $tnname2 = substr($tnemail['email'], 0, strrpos($tnemail['email'], '+g'));

                if (array_key_exists($tnname2, $tnusers))
                {
                    error_log("WARNING: ...{$tnemail['email']} uses old +g format, convert");
                    $u = User::get($dbhr, $dbhm, $uid);
                    $u->removeEmail($tnemail['email']);
                    $email = str_replace('+g', '-g', $tnemail['email']);
                    $u->addEmail($email);
                    $gotvalid = TRUE;
                }
            } else if (strpos($tnemail['email'], '-x') !== false)
            {
                $tnname2 = substr($tnemail['email'], 0, strrpos($tnemail['email'], '-x'));

                if (array_key_exists($tnname2, $tnusers))
                {
                    error_log("WARNING: ...{$tnemail['email']} uses old -x format, convert");
                    $u = User::get($dbhr, $dbhm, $uid);
                    $u->removeEmail($tnemail['email']);
                    $email = str_replace('-x', '-g1', $tnemail['email']);
                    $u->addEmail($email);
                    $gotvalid = TRUE;
                }
            } else {
                $tnname2 = substr($tnemail['email'], 0, strrpos($tnemail['email'], '@'));

                if (array_key_exists($tnname2, $tnusers))
                {
                    error_log("...{$tnemail['email']} has no group info");
                    $u = User::get($dbhr, $dbhm, $uid);
                    $u->removeEmail($tnemail['email']);
                    $u->addEmail("$tnname2-g1@user.trashnothing.com");
                    $gotvalid = TRUE;
                }
            }

            if (!$gotvalid) {
                error_log("ERROR: {$tnemail['email']} invalid format and no valid email found, delete");
                $u = User::get($dbhr, $dbhm, $uid);
                $u->forget("Invalid TN email {$tnemail['email']} which we can't salvage.");
            }
        } else {
            # Found a valid email.  Delete the invalid one.
            error_log("ERROR: ...TN user has invalid format email $tnname ({$tnemail['email']}) but others exist");
            $dbhm->preExec("DELETE FROM users_emails WHERE id = ?;", [
                $tnemail['id']
            ]);
        }
    } else {
        if (!array_key_exists($tnname, $tnusers)) {
            error_log("...TN user $tnname ({$tnemail['email']}) no longer exists on TN");
            $dbhm->preExec("DELETE FROM users_emails WHERE id = ?;", [
                $tnemail['id']
            ]);
            $removed++;
        } else {
            # The TN user we have on FD exists on TN as you'd expect.  Check that this FD user only has email addresses
            # for one TN name.
            $otheremails = $dbhr->preQuery("SELECT email, added FROM users_emails WHERE userid = ? AND email LIKE '%@user.trashnothing.com';", [
                $uid
            ]);

            $thistnnames = [];
            $latestname = NULL;
            $latestadded = NULL;

            foreach ($otheremails as $otheremail)
            {
                $tnname2 = substr($otheremail['email'], 0, strrpos($otheremail['email'], '-g'));

                if (strlen($tnname2)) {
                    # We have a different name.
                    $thisadded = strtotime($otheremail['added']);
                    $thistnnames[$tnname2] = $thisadded;

                    if ($thisadded > $latestadded) {
                        $latestadded = $thisadded;
                        $latestname = $tnname2;
                    }
                }
            }

            if (count($thistnnames) > 1) {
                # There are various ways that this could have happened, and some of them are not ones we can identify.
                # So we keep the most recently added TN name, and delete the others.
                error_log("WARNING: FD #$uid $tnname has multiple TN names.  Latest is $latestname, delete old.");
                $multiples++;
                $tnesc = str_replace('_', '\_', $latestname);
                $dbhm->preExec("DELETE FROM users_emails WHERE userid = ? AND email LIKE '%@user.trashnothing.com' AND email NOT LIKE '$tnesc-g%@user.trashnothing.com';", [
                    $uid
                ]);
            }
        }
    }
}

error_log("\n\nRemoved $removed of $count TN emails\n");
error_log("Updated TN userids $updatedid\n");
error_log("Removed multiple TN emails for $multiples users\n");

if ($updatedfailed) {
    error_log("ERROR: Failed to update $updatedfailed TN userids\n");
}

error_log("$bademail bad emails\n");

// Now merge any users that TN knows have been renamed, but which we have as separate users.
$fh = fopen('/tmp/tn-new-and-old-usernames.csv', 'r');
$merged = 0;

while (!feof($fh))
{
    $fields = fgetcsv($fh);
    $tnid = $fields[0];
    $tncurrent = $fields[1];
    $tnold = $fields[2];
    $tnfdid = $fields[3];

    $tncurrentesc = str_replace('_', '\_', $tncurrent);
    $tnoldesc = str_replace('_', '\_', $tnold);

    $u = User::get($dbhr, $dbhm);

    do {
        $current = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users_emails WHERE email LIKE '$tncurrentesc-g%@user.trashnothing.com';");

        if (count($current) > 1) {
            $u->merge($current[0]['userid'], $current[1]['userid'], 'Separate FD users which TN knows are the same.');
            $merge++;
        }
    } while (count($current) > 1);

    do {
        $old = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users_emails WHERE email LIKE '$tnoldesc-g%@user.trashnothing.com';");

        if (count($old) > 1) {
            $u->merge($old[0]['userid'], $old[1]['userid'], 'Separate FD users which TN knows are the same.');
            $merge++;
        }
    } while (count($old) > 1);

    if (count($current) == 1 && count($old) == 1 && $current[0]['userid'] != $old[0]['userid']) {
        // We found two separate users which should be the same.  Merge them and delete the old email addresses.
        #error_log("Merge $tnold into $tncurrent");
        if ($u->merge($current[0]['userid'], $old[0]['userid'], 'Separate FD users which TN knows are renamed.')) {
            $dbhm->preExec("DELETE FROM users_emails WHERE userid = ? AND email LIKE '$tnoldesc-g%@user.trashnothing.com';", [
                $old[0]['userid']
            ]);
            $merge++;
        }
    }
}

fclose($fh);
error_log("\n\nMerged $merged");

// Now ensure that each FD user has a TN userid and emails which match the userid and name in the TN files.
$tnidmoved = 0;
$tniddiffers = 0;
$tnresub = 0;

foreach ($tnusers as $tnname => $details) {
    $tnesc = str_replace('_', '\_', $tnname);

    if (!$details['old']) {
        // Current name.
        $tnid = $details['id'];

        $fdusers = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users_emails WHERE email LIKE '$tnesc-g%@user.trashnothing.com' AND email REGEXP '^$tnname-g[0-9]*@user.trashnothing.com';");

        if (count($fdusers) > 1) {
            error_log("ERROR: Multiple FD users found for $tnname = $tnesc; should not happen");
            exit(1);
        } else if (!count($fdusers)) {
            #error_log("WARNING: TN user $tnname (TN id {$details['id']}) has no FD user.  This is OK if they have never joined a group.");
            #$tnresub++;
        } else {
            $uid = $fdusers[0]['userid'];
            $u = User::get($dbhr, $dbhm, $uid);

            // Check if the TN userid is in user by another FD user.  If so, then that other user is wrong.
            $other = $dbhr->preQuery("SELECT id FROM users WHERE tnuserid = ? AND id != ?;", [
                $tnid,
                $uid
            ]);

            if (count($other)) {
                error_log("WARNING: TN user $tnname has FD user {$other[0]['id']} which has same TN userid $tnid, move TN userid to FD user $uid");
                $u2 = User::get($dbhr, $dbhm, $other[0]['id']);
                $u2->setPrivate('tnuserid', NULL);
                $u->setPrivate('tnuserid', $tnid);
                $tnidmoved++;
            } else if ($u->getPrivate('tnuserid') != $tnid) {
                error_log("WARNING: TN user $tnname is FD user $uid which has different TN userid {$u->getPrivate('tnuserid')} != $tnid, correct TN userid to $tnid");
                $u->setPrivate('tnuserid', $tnid);
                $tniddiffers++;
            }
        }
    }
}

error_log("$tnresub TN resubsubscribes required\n");
error_log("Moved $tnidmoved TN userids\n");
error_log("Corrected $tniddiffers TN userids\n");

# Now check TN users for FD ids which don't match
foreach ($tnusers as $tnname => $details) {
    $tnid = $details['id'];
    $tnname = $details['name'];
    $tnfdid = $details['tnfdid'];
    $tnesc = str_replace('_', '\_', $tnname);

    if ($tnfdid) {
        $fdusers = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users_emails WHERE email LIKE '$tnesc-g%@user.trashnothing.com' AND email REGEXP '^$tnname-g[0-9]*@user.trashnothing.com';");

        if (count($fdusers) > 1) {
            error_log("ERROR: Multiple FD users found for $tnname = $tnesc; should not happen");
            exit(1);
        } else if (count($fdusers)) {
            if ($fdusers[0]['userid'] != $tnfdid) {
                error_log("ERROR TN user $tnname has FD user $tnfdid but should be {$fdusers[0]['userid']}");
            }
        }
    }
}