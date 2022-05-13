<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

if (($handle = fopen("/tmp/tnnames.csv", "r")) !== FALSE)
{
    fgetcsv($handle);

    while (($data = fgetcsv($handle, 1000, ",")) !== false) {
        $tnid = intval($data[0]);
        $tnname = trim($data[1]);
        $tnold = trim($data[2]);
        $fdid = intval($data[3]);

        # If we have the TNID for a user, check the name is correct.
        $users = $dbhr->preQuery("SELECT id, tnuserid, fullname FROM users WHERE tnuserid = ? AND deleted IS NULL", [
            $tnid
        ]);

        foreach ($users as $user) {
            error_log("$tnid, $tnname, $tnold => {$user['id']} {$user['fullname']}");

            if (User::removeTNGroup($user['fullname']) != $tnname) {
                error_log("...name mismatch");
                $dbhm->preExec("UPDATE users SET fullname = ? WHERE id = ?", [
                    $tnname,
                    $user['id']
                ]);
            }
        }

        # If we have the FD id for a user, check it is correct.
        if ($fdid) {
            $u = User::get($dbhr, $dbhm, $fdid);

            if ($u->getId() == $fdid) {
                #error_log("...FD id matches");
            } else {
                if (count($users)) {
                    error_log("TN user $tnid FD id $fdid is invalid - should be {$users[0]['id']}");
                } else {
                    error_log("TN user $tnid FD id $fdid is invalid - deleted?");
                }
            }
        }

        # Check if we have accounts for both the old and new names.
        $newusers = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users_emails WHERE email LIKE '$tnname-g%@user.trashnothing.com'");
        $oldusers = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users_emails WHERE email LIKE '$tnold-g%@user.trashnothing.com'");

        if (count($newusers) && count($oldusers)) {
            $newids = array_column('userid', $newusers);
            $oldids = array_column('userid', $oldusers);

            if ($newids != $oldids) {
                error_log("TN user $tnid, $tnname, $tnold has FD ids " . json_encode($newids) . " and " . json_encode($oldids));
            } else {
                error_log("TN user $tnid, $tnname, $tnold same IDs");
            }
        }
    }
}