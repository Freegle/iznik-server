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
$tnusers = $dbhr->preQuery("SELECT email, userid, tnuserid FROM users_emails INNER JOIN users ON users.id = users_emails.userid WHERE email LIKE '%trashnothing%' AND LOCATE('-', email) AND users.added < '2022-08-12';");

error_log(count($tnusers) . " TN users");

$count = 0;
$unknown = 0;
$wrongFDIDonTN = 0;
$wrongTNIDonFD = 0;
$TNFDaddedToFD = 0;
$wrongTNNameOnFD = 0;
$TNIDalreadyInUse = 0;
$processed = [];

foreach ($tnusers as $tnuser) {
    // Get part of email before -
    $tnname = substr($tnuser['email'], 0, strrpos($tnuser['email'], '-g'));

    if (!array_key_exists($tnname, $processed) || $processed[$tnname] != $tnuser['tnuserid']) {
        $processed[$tnname] = $tnuser['userid'];

        if (array_key_exists($tnname, $tnnames))
        {
            if ($tnnames[$tnname]['fdid'] && $tnnames[$tnname]['fdid'] != $tnuser['userid'])
            {
                error_log(
                    "TN user $tnname has FD on TN as {$tnnames[$tnname]['fdid']} but FD is {$tnuser['userid']} - TN needs correcting"
                );
                $wrongFDIDonTN++;
            }

            if ($tnuser['tnuserid'] != $tnnames[$tnname]['tnid'])
            {
                $others = $dbhr->preQuery("SELECT id FROM users WHERE tnuserid = ?;", [
                    $tnnames[$tnname]['tnid']
                ]);

                if ($tnuser['tnuserid']) {
                    if (count($others)) {
                        # The FD user with this email does have a TN userid, but it's wrong, and the correct TN userid is
                        # in use by a different FD user.  This means that the email is attached to the wrong FD user.
                        error_log("TN user $tnname has TN userid {$tnnames[$tnname]['tnid']} whereas FD has {$tnuser['tnuserid']} - correct FD");
                        $dbhm->preQuery("UPDATE users_emails SET userid = ? WHERE email = ?;", [
                            $others[0]['id'],
                            $tnuser['email']
                        ]);
                    } else {
                        # The FD user with this email does have a TN userid, it's wrong, and the correct TN userid is
                        # not in use.  So we can just correct this user.
                        $dbhm->preQuery("UPDATE users SET tnuserid = ? WHERE id = ?;", [
                            $tnnames[$tnname]['tnid'],
                            $tnuser['userid']
                        ]);
                    }

                    $wrongTNIDonFD++;
                } else {
                    # The FD user with this email doesn't have a TN userid.  We can add it, unless it's in use elsewhere.
                    if (!count($others)) {
                        error_log("TN user $tnname has TN userid {$tnnames[$tnname]['tnid']} - FD needs adding");
                        $dbhm->preQuery("UPDATE users SET tnuserid = ? WHERE id = ?;", [
                            $tnnames[$tnname]['tnid'],
                            $tnuser['userid']
                        ]);
                        $TNFDaddedToFD++;
                    } else {
                        error_log("TN user $tnname has TN userid {$tnnames[$tnname]['tnid']} which is in use by FD user {$others[0]['id']} - needs checking");
                        $TNIDalreadyInUse++;
                    }
                }
            }
        } else if ($tnuser['tnuserid']) {
            # We have a TN userid, so we know who this is.
        } else {
            // We might have an email address for this FD user which is known on TN.
            $others = $dbhr->preQuery("SELECT email FROM users_emails WHERE userid = ? AND email NOT LIKE '$tnname%';", [
                $tnuser['userid']
            ]);

            $found = FALSE;
            foreach ($others as $other) {
                $tnname2 = substr($other['email'], 0, strrpos($other['email'], '-g'));
                $tnname2 = str_replace('_', '\_', $tnname2);

                if (strlen($tnname2) && array_key_exists($tnname2, $tnnames)) {
                    $found = TRUE;
                    error_log("FD $tnname also known as $tnname2 on TN - FD needs correcting, perhaps splitting.");
                    $wrongTNNameOnFD++;
                    break;
                }
            }

            if (!$found) {
                error_log("FD TN user $tnname not on TN with that name - needs checking.");
                $unknown++;
            }
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("$count");
    }
}

error_log("$unknown unknown on TN, $wrongFDIDonTN wrong FD id on TN, $wrongTNIDonFD wrong TN id on FD, $TNFDaddedToFD TN ID added to FD, $wrongTNNameOnFD wrong TN name on FD, $TNIDalreadyInUse TN ID already in use");