<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$oldnames = [];
if (($handle = fopen("/tmp/tn-user-to-fd-user-id-old-usernames", "r")) !== FALSE)
{
    while (($data = fgetcsv($handle, 1000, "\t")) !== FALSE)
    {
        $tnid = $data[0];
        $tnusername = $data[1];
        $fdid = $data[2];
        $oldnames[$tnusername] = $tnid;
    }
}

if (($handle = fopen("/tmp/tn-user-to-fd-user-id", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, "\t")) !== FALSE) {
        $tnid = $data[0];
        $tnusername = $data[1];
        $fdid = $data[2];

        if ($fdid) {
            $u = new User($dbhr, $dbhm, $fdid);

            if ($u->getId() == $fdid) {
                $emails = $u->getEmails();
                foreach ($emails as $email) {
                    if (strpos($email['email'], 'trashnothing') !== FALSE) {
                        if (strpos($email['email'], $tnusername) !== 0) {
                            # Perhaps we have an old name,
                            $p = strpos($email['email'], '-');
                            $name = substr($email['email'], 0, $p);

                            if (array_key_exists($name, $oldnames)) {
                                #error_log("Got old name $name");
                                if ($oldnames[$name] == $tnid) {
                                    #error_log("...matches");
                                } else {
                                    error_log("FD $fdid, TN $tnid, TN username $tnusername has email {$email['email']} which is for TN {$oldnames[$name]}");
                                }
                            } else {
                                error_log("FD $fdid, TN $tnid, TN username $tnusername has email {$email['email']} which doesn't match an old name");
                            }
                        }
                    }
                }
            } else {
                error_log("Invalid FD id $fdid for TN $tnid");
            }
        }
    }
}
