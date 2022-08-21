<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

error_log("Loading TN names");
$fh = fopen('/tmp/tnnames.csv', 'r');
$tnnames = [];

while (!feof($fh))
{
    $fields = fgetcsv($fh);
    $tnid = $fields[0];
    $tnname = str_replace('_', '\_', $fields[1]);
    $fdid = $fields[2];
    $tnnames[$tnname] = [ 'tnid' => $tnid, 'fdid' => $fdid ];
}

error_log("Loaded, query for TN users");
$tnusers = $dbhr->preQuery("SELECT userid, tnuserid FROM users_emails INNER JOIN users ON users.id = users_emails.userid WHERE email LIKE '%trashnothing%' AND LOCATE('-', email) AND users.added < '2022-08-12';");

error_log(count($tnusers) . " TN users");

$count = 0;
$unknown = 0;
$fdmissingtnid = 0;
$emailmoved = 0;
$tnfdidwrong = 0;

foreach ($tnusers as $tnuser) {
    # Check each email for this user.
    $emails = $dbhr->preQuery("SELECT id, email FROM users_emails WHERE userid = {$tnuser['userid']};");

    foreach ($emails as $email) {
        $tnname = substr($tnuser['email'], 0, strrpos($tnuser['email'], '-g'));
        $tnname = str_replace('_', '\_', $tnname);

        # We now have a name which FD thinks is associated with this TN user.
        if (!array_key_exists($tnname, $tnnames)) {
            # TN doesn't know this name at all.  This is possible if the user was renamed on TN before TN recorded
            # the rename.  It's unclear what we do.
            error_log(
                "unknown: FD {$tnuser['userid']} uses TN name {$tnname} which is not known on TN at all - needs work."
            );
            $unknown++;
        } else if ($tnnames[$tnname]['fdid'] == $email['userid']) {
            # TN has the right FD id for this name and hence email.  This means the email is attached to the right
            # FD user.

        } else if ($tnnames[$tnname]['fdid'] != $tnuser['tnuserid']) {
            # TN has an FD id but it is different.  This is possible if the user has been merged on FD.  TN needs
            # updating.
            $fdidontn = $tnnames[$tnname]['fdid'];

            error_log("tnfdidwrong: FD #{$tnuser['userid']} uses TN name {$tnname} but TN thinks $tnname is for FD {$fdidontn} - TN needs updating.");
            $tnfdidwrong++;
        } else {
            # TN has the right FD id.
            $tnid = $tnnames[$tnname]['tnid'];
            $fdidontn = $tnnames[$tnname]['fdid'];
            error_log("{$tnuser['userid']} {$tnname} {$tnid} {$fdid}");

            $otherwithtnid = $dbhr->preQuery("SELECT userid FROM users_emails WHERE email LIKE '%trashnothing%' AND LOCATE('-', email) AND userid != {$tnuser['userid']} AND tnuserid = {$tnid};");

            if (!$tnuser['tnuserid']) {
                # FD is missing the TN userid.
                if (!count($otherwithtnid)) {
                    # The TN userid is not in use elsewhere, so we can just update this user to have the correct one.
//                    $dbhm->preExec("UPDATE users SET tnuserid = ? WHERE id = ?", [
//                        $tnid,
//                        $tnuser['userid']
//                    ]);

                    error_log("fdmissingtnid: FD #{$tnuser['userid']} has no TN userid, but {$tnid} is not in use elsewhere - updated");
                    $fdmissingtnid++;
                } else {
                    # The TN userid for this name and hence email is in use for another FD member.  This means that this
                    # email is attached to the wrong FD user.
//                    $dbhm->preExec("UPDATE users_emails SET userid = ? WHERE id = ?", [
//                        $otherwithtnid[0]['userid'],
//                        $email['id']
//                    ]);
                    error_log("emailmoved: FD #{$tnuser['userid']} has email {$email['email']} which should be attached to FD #{otherwithtnid[0]['userid']} - email moved");
                    $emailmoved++;
                }
            } else if ($tnid != $tnuser['tnuserid']) {
                # FD has the wrong TN userid.  This probably means that the email is attached to the wrong user.
                error_log("FD #{$tnuser['userid']} TN name {$tnname}  TN id {$tnid} {$tnuser['tnuserid']}");
                $count++;
            }
            $dbhm->preExec("UPDATE users_emails SET tnuserid = ? WHERE id = ?;", [ $tnid, $email['id'] ]);
            $count++;
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("$count");
    }
}

