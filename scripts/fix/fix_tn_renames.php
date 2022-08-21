<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';

require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
global $dbhr, $dbhm;

error_log("Loading TN names");
$fh = fopen('/tmp/tn-new-and-old-usernames.csv', 'r');
$tnnames = [];
$merge = 0;
$manual = 0;
$deloldemail = 0;
$nonew = 0;

while (!feof($fh))
{
    $fields = fgetcsv($fh);
    $tnid = $fields[0];
    $tncurrent = str_replace('_', '\_', $fields[1]);
    $tnold = str_replace('_', '\_', $fields[2]);
    $tnfdid = $fields[3];

    $oldemails = $dbhr->preQuery("SELECT DISTINCT userid FROM users_emails WHERE email like '$tnold-g%@user.trashnothing.com';");

    if (count($oldemails) > 1) {
        error_log("TN old user $tnold #$tnid has multiple FD users " . implode(',', array_column($oldemails, 'userid')) . " - check manually and merge");
        $manual++;
    } else if (count($oldemails)) {
        $newemails = $dbhr->preQuery("SELECT DISTINCT userid FROM users_emails WHERE email like '$tncurrent-g%@user.trashnothing.com';");

        if (count($newemails) > 1) {
            error_log("TN current user $tncurrent #$tnid has multiple FD users " . implode(',', array_column($newemails, 'userid')) . " - check manually and merge");
            $manual++;
        } else if (count($newemails)) {
            if ($oldemails[0]['userid'] == $newemails[0]['userid']) {
                # We want to delete any email addresses with the old ID, as we no longer need them, and it confuses the fix_tn_ids script.
                #error_log("TN user $tncurrent was $tnold and both are FD {$oldemails[0]['userid']} - fine");
                $tokeeps = $dbhr->preQuery("SELECT id, email FROM users_emails WHERE userid = {$oldemails[0]['userid']} AND email LIKE '$tncurrent-g%@user.trashnothing.com';");
                $todels = $dbhr->preQuery("SELECT id, email FROM users_emails WHERE userid = {$oldemails[0]['userid']} AND email NOT LIKE '$tncurrent-g%@user.trashnothing.com';");

                if (count($todels)) {
                    if (!count($tokeeps)) {
                        error_log("TN user $tncurrent was has old email addresses for $tnold - but no new ones");
                        $nonew++;
                    } else {
                        foreach ($todels as $todel) {
                            error_log("TN user $tncurrent was has old email address for $tnold {$todel['email']} - delete it");
                            $dbhm->preExec("DELETE FROM users_emails WHERE id = {$todel['id']};");
                        }

                        $deloldemail++;
                    }
                }
            } else {
                error_log("TN current user $tncurrent is FD {$newemails[0]['userid']} but TN old user $tnold is FD {$oldemails[0]['userid']} - merge");
                $u = new User($dbhr, $dbhm);
                $u->merge($oldemails[0]['userid'], $newemails[0]['userid'], 'Merge as renamed on TN');
                $merge++;
            }
        } else {
            #error_log("FD has no record of new TN name $tncurrent - fine");
        }
    } else {
        #error_log("FD has no record of old TN name $tnold - fine");
    }
}

error_log("\n\n$merge merged, $deloldemail old emails deleted, $manual manual checks required, $nonew old emails but no new ones");