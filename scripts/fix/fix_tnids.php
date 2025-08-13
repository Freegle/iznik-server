<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$fh = fopen('/tmp/tnold.csv', 'r');
$oldnames = [];

while (!feof($fh))
{
    $fields = fgetcsv($fh);
    $tnid = $fields[0];
    $tnname = $fields[1];
    $oldnames[$tnid][] = $tnname;
}

$fh = fopen('/tmp/tn.csv', 'r');
$count = 0;

if ($fh) {
    while (!feof($fh)) {
        $fields = fgetcsv($fh);
        $tnid = $fields[0];
        $tnname = $fields[1];
        $fdid = $fields[2];

        try {
            if ($fdid) {
                $u = new User($dbhr, $dbhm, $fdid);

                if ($u->getId() == $fdid) {
                    $fdhas = $u->getPrivate('tnuserid');
                    if ($fdhas) {
                        if ($fdhas != $tnid) {
                            error_log("FD $fdid has TN userid $fdhas but TN says $tnid for $tnname");
                        }
                    } else {
                        #error_log("Add TN id $tnid to FD $fdid");
                        $u->setPrivate('tnuserid', $tnid);
                    }
                } else {
                    error_log("TN has invalid FD userid $fdid for #$tnid $tnname");
                }
            } else {
                $names = [ $tnname ];
                if (array_key_exists($tnid, $oldnames)) {
                    $names = array_merge_recursive($names, $oldnames[$tnid]);
                }

                $found = FALSE;

                foreach ($names as $name) {
                    #error_log("...check name $name for $tnid");
                    $emails = $dbhr->preQuery(
                        "SELECT DISTINCT userid FROM users_emails WHERE email like '$name-g%@user.trashnothing.com';"
                    );

                    if (count($emails) > 1) {
                        error_log("Multiple FD accounts found for #$tnid $name");
                    } else {
                        if (count($emails) == 1) {
                            #error_log("Found FD {$emails[0]['userid']} for #$tnid $tnname");
                            $u = new User($dbhr, $dbhm, $emails[0]['userid']);
                            $u->setPrivate('tnuserid', $tnid);
                            $found = TRUE;
                        }
                    }
                }

                if (!$found) {
                    error_log("TN member #$tnid $tnname not found");
                }
            }
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== FALSE) {
                $dups = $dbhr->preQuery("SELECT id FROM users WHERE tnuserid = ?;", [
                    $tnid
                ]);
                error_log("Duplicate TNID entry $tnid on #$tnid $tnname #$fdid, already assigned to FD #{$dups[0]['id']}");
            } else {
                error_log("Exception {$e->getMessage()}");
            }
        }

        $count++;

        if ($count % 1000 == 0) {
            error_log("...$count");
        }
    }
}
