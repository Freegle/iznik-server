<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
require_once(IZNIK_BASE . '/include/misc/Location.php');
require_once(IZNIK_BASE . '/include/misc/PAF.php');
require_once(IZNIK_BASE . '/include/group/Admin.php');

# Stats
$users_known = 0;
$users_new = 0;
$users_noemail = 0;
$postcode_mapped = 0;
$postcode_untouched = 0;
$postcode_failed = 0;
$address_mapped = 0;
$address_failed = 0;
$emaildaily = 0;
$emailnever = 0;
$emailimmediate = 0;
$posts = 0;

# User filter for testing this before we go live.
$userfilt = " AND u_Id IN (9, 11, 54) ";
$userfilt = " AND u_Moderator = 1 ";
$userfilt = "";

# Whether we're doing a test migration i.e. no actual data change.
$test = FALSE;

$dsn = "mysql:host={$dbconfig['host']};dbname=Norfolk;charset=utf8";

$dbhn = new LoggedPDO($dsn, $dbconfig['user'], $dbconfig['pass']);

$start = date('Y-m-d', strtotime("30 years ago"));
$alluserssql = "SELECT * FROM u_User
              WHERE u_Id IN
   (SELECT DISTINCT u_Id FROM u_User
       LEFT JOIN pr_PostResponder ON pr_PostResponder.pr_u_Id_Responder = u_User.u_Id
       LEFT JOIN p_Post ON p_Post.p_u_Id = u_User.u_Id
   WHERE u_IsActive = 1 AND u_DontDelete = 0 AND u_IsActivated = 1 AND (p_DatePosted >= '$start' OR pr_LastUpdatedDt >= '$start' OR u_CreatedDt >= '$start'));";

$users = $dbhn->preQuery($alluserssql);
$total = count($users);
$count = 0;
$u = new User($dbhr, $dbhm);

error_log("Migrate $total users\n");

# Locations
error_log("\nMigrate locations\n");
$users = $dbhn->preQuery($alluserssql);
$total = count($users);
foreach ($users as $user) {
    # Do we have the user on the system?  Might not if we have migrated a subset of users.
    $sql = "SELECT * FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 ORDER BY ue_ModifiedDt DESC LIMIT 1;";
    $emails = $dbhn->preQuery($sql, [
        $user['u_Id']
    ]);

    if (count($emails)) {
        foreach ($emails as $email) {
            $e = str_replace('.NERFED', '', $email['ue_EmailAddress']);
            $uid = $u->findByEmail($e);

            if ($uid) {
                # Use the most recent location.  Some users have multiple - we have agreed not to care.
                $locations = $dbhn->preQuery("SELECT * FROM ul_UserLocation WHERE ul_u_Id = ? AND ul_Primary = 1 AND ul_Active = 1 ORDER BY ul_ModifiedDt DESC LIMIT 1;", [
                    $user['u_Id']
                ]);

                if (!count($locations)) {
                    error_log("$e locations:");
                    $locations = $dbhn->preQuery("SELECT * FROM ul_UserLocation WHERE ul_u_Id = ? ORDER BY ul_ModifiedDt DESC LIMIT 1;", [
                        $user['u_Id']
                    ]);

                    foreach ($locations as $location) {
                        # Set the user's location to be that postcode.
                        $l = new Location($dbhr, $dbhm);
                        $locs = $l->typeahead($location['ul_PostCode'], 1, TRUE);

                        if (count($locs)) {
                            $near = $locs[0]['groupsnear'];
                            if (count($near)) {
                                $gid = $near[0]['id'];

                                error_log("...{$location['ul_PostCode']} found, within FD community #$gid {$near[0]['nameshort']}");

                                # Set our location.
                                $u = new User($dbhr, $dbhm, $uid);
                                $nfadded = strtotime($user['u_CreatedDt']);
                                $fdadded = strtotime($u->getPrivate('added'));
                                #error_log("...compare $nfadded vs $fdadded");

                                if ($nfadded < $fdadded) {
                                    error_log("...added earlier on NF, update");
                                    $u->setPrivate('added', $user['u_CreatedDt']);
                                }

                                # Get last access
                                $sql = "SELECT MAX(GREATEST(p_DatePosted, pr_LastUpdatedDt, u_CreatedDt)) AS lastaccess
    FROM u_User
    LEFT JOIN p_Post ON p_Post.p_u_Id = u_User.u_Id
    LEFT JOIN pr_PostResponder ON pr_PostResponder.pr_u_Id_Responder = u_User.u_Id
    WHERE u_Id = ? AND u_IsActivated = 1 AND (p_DatePosted >= '$start' OR pr_LastUpdatedDt >= '$start' OR u_CreatedDt >= '$start');";
                                $lasts = $dbhn->preQuery($sql, [
                                    $user['u_Id']
                                ]);

                                $lastaccess = $lasts[0]['lastaccess'];
                                error_log("...last access $lastaccess");

                                $u->setPrivate('lastaccess', $lastaccess);

                                $settings = $u->getPrivate('settings');
                                $settings = json_decode($settings, TRUE);

                                if (Utils::pres('mylocation', $settings)) {
                                    $name = $settings['mylocation']['name'];

                                    if (!strcmp($name, $location['ul_PostCode'])) {
                                        error_log("...matches existing FD location $name");
                                        $postcode_mapped++;
                                    } else {
                                        error_log("...existing FD location $name, leave untouched");
                                        $postcode_untouched++;
                                    }
                                } else {
                                    $l = new Location($dbhr, $dbhm, $locs[0]['id']);
                                    $settings['mylocation'] = $l->getPublic();

                                    error_log("...no existing FD location, set #{$settings['mylocation']['id']} {$settings['mylocation']['name']}");
                                    $postcode_mapped++;

                                    if (!$test) {
                                        $u->setPrivate('settings', json_encode($settings));
                                    }
                                }

                                # Set our membership.
                                if ($u->isApprovedMember($gid)) {
                                    error_log("...already a member");
                                } else {
                                    $role = $user['u_Moderator'] ? User::ROLE_MODERATOR : User::ROLE_MEMBER;
                                    error_log("...add with role $role");
                                    if (!$test) {
                                        $u->addMembership($gid, $role);
                                    }
                                }

                                # Find the migration ADMIN
                                $admins = $dbhr->preQuery("SELECT * FROM admins WHERE text LIKE '%join the national%' AND groupid = ?;", [
                                    $gid
                                ]);

                                foreach ($admins as $admin) {
                                    $a = new Admin($dbhr, $dbhm, $admin['id']);
                                    $a->mailMembers(TRUE, $u->getId());
                                }

                                # Set moderation status.
                                $modstatus = $user['u_IsModerated'] ? Group::POSTING_MODERATED : Group::POSTING_DEFAULT;
                                error_log("...moderation status $modstatus");

                                if (!$test) {
                                    $u->setMembershipAtt($gid, 'ourPostingStatus', $modstatus);
                                }

                                # Set email frequency.
                                $count = $dbhn->preQuery("SELECT COUNT(*) AS count FROM ut_UserCategory WHERE ut_u_Id = ?;", [
                                    $user['u_Id']
                                ],
                                    FALSE,
                                    FALSE);

                                if (!$count[0]['count']) {
                                    $emailfreq = 0;
                                    error_log("...email frequency Never");

                                    $emailnever++;
                                } else if ($user['u_DailyAlerts']) {
                                    $emailfreq = 24;
                                    error_log("...email frequency Daily");
                                    $emaildaily++;
                                } else {
                                    $emailfreq = -1;
                                    error_log("...email frequency Immediate");
                                    $emailimmediate++;
                                }

                                if (!$test) {
                                    $u->setMembershipAtt($gid, 'emailfrequency', $emailfreq);
                                    $u->setMembershipAtt($gid, 'added', $user['u_CreatedDt']);
                                }
                            } else {
                                error_log("ERROR: Can't map NF postcode {$location['ul_PostCode']} to a community");
                                $postcode_failed++;
                            }
                        } else {
                            error_log("ERROR: Can't map NF postcode {$location['ul_PostCode']} to an FD postcode");
                            $postcode_failed++;
                        }

                        if (Utils::pres('ul_Address', $location)) {
                            $oneliner = trim(preg_replace('/\s\s+/', ' ', $location['ul_Address']));

                            # Special cases.
                            $oneliner = str_replace('***Please note that my address is not often on sat navs/GPS.*** Address: ', '', $oneliner);
                            $oneliner = str_replace('Linewath', 'Linewaith', $oneliner);
                            $oneliner = str_replace('Hollybrook House 85 Silver Road', '85 Silver Road', $oneliner);
                            $oneliner = str_replace('The Hawthorns 60 High Street', '60 High Street', $oneliner);
                            $oneliner = str_replace('Blacksmith\'s Cottage Church Lane', 'Blacksmith Cottage Church Lane', $oneliner);
                            $oneliner = str_replace('10 Harry Chamberlain', 'Flat 10, Harry Chamberlain', $oneliner);
                            $oneliner = str_replace('4, The Raveningham Centre', 'Unit 4, The Raveningham Centre', $oneliner);
                            $oneliner = str_replace('My address is ', '', $oneliner);
                            $oneliner = str_replace('"Romayne"', 'Romayne', $oneliner);
                            $oneliner = str_replace('No 3 Council Houses', '3 Council Houses', $oneliner);
                            $oneliner = str_replace('Attleborough 7 Tulip Close', '7 Tulip Close', $oneliner);
                            $oneliner = str_replace('\'Holly Lodge\' 60A', '60a', $oneliner);
                            $oneliner = str_replace('Orchard Cottage, 20 High Street', '20 High Street', $oneliner);
                            $oneliner = str_replace('PALGRAVE HALL COTTAGES 6 PALGRAVE ROAD', '6 Palgrave Hall Cottages, Palgrave Road', $oneliner);
                            $oneliner = str_replace('Cairncroft Waxham', 'Cairn Croft Waxham', $oneliner);
                            $oneliner = str_replace('The Dog Shed16 Melton', '16 Melton', $oneliner);
                            $oneliner = str_replace('Charnwood Lodge, 41, Greevegate', '41 Greevegate', $oneliner);
                            $oneliner = str_replace('The Sunshine House, ', '', $oneliner);
                            $oneliner = str_replace('Hollyhocks 6', '6', $oneliner);
                            $oneliner = str_replace('Oakleigh 5', '5', $oneliner);
                            $oneliner = str_replace('Beach View 2', '2', $oneliner);
                            $oneliner = str_replace('12a the annex,', 'Annexe, 12', $oneliner);
                            $oneliner = str_replace('11Albion', '11 Albion', $oneliner);
                            $oneliner = str_replace('9.CLARE RD.', '9 Clare Road', $oneliner);
                            $oneliner = str_replace('Seashore 424', '424', $oneliner);
                            $oneliner = str_replace('Malcolm Lake 54', '54', $oneliner);
                            $oneliner = str_replace('South View Cottage 2', '2', $oneliner);

                            # Might not have the uid in a test migration.
                            if ($uid) {
                                # Check if we already have an address in FD at that postcode - if so, then assume it's the same
                                # and no need to migrate.
                                $existings = $dbhr->preQuery("SELECT locations.name FROM users_addresses INNER JOIN paf_addresses ON paf_addresses.id = users_addresses.pafid INNER JOIN locations ON locations.id = paf_addresses.postcodeid WHERE userid = ?", [
                                    $uid
                                ]);

                                $already = FALSE;
                                $canonpcnf = strtolower(str_replace(' ', '', $location['ul_PostCode']));

                                foreach ($existings as $existing) {
                                    $canonpcfd = strtolower(str_replace(' ', '', $existing['name']));

                                    if (!strcmp($canonpcnf, $canonpcfd)) {
                                        $already = TRUE;
                                        error_log("......already got FD address at same postcode as $oneliner, ignore");
                                        $address_mapped++;
                                    }
                                }
                            }

                            if (!$already) {
                                $mapped = FALSE;

                                if (preg_match('/(.*?)( |,)/', $oneliner, $matches)) {
                                    $house = $matches[1];

                                    $fdlocs = $dbhr->preQuery("SELECT id FROM paf_addresses WHERE postcodeid = ?;", [
                                        $locs[0]['id']
                                    ]);

                                    foreach ($fdlocs as $fdloc) {
                                        $a = new PAF($dbhr, $dbhm, $fdloc['id']);
                                        $fdoneline = $a->getSingleLine($fdloc['id']);

                                        if (strpos(strtolower($fdoneline), strtolower("$house ")) === 0) {
                                            error_log("......map NF address $oneliner to FD address $fdoneline");
                                            $mapped = TRUE;
                                            $address_mapped++;

                                            if (!$test && $uid) {
                                                # Add in the address and any locations.
                                                $a = new Address($dbhr, $dbhm);
                                                $aid = $a->create($uid, $fdloc['id'], Utils::presdef('ul_Directions', $location, NULL));
                                            }
                                        }
                                    }
                                }

                                if (!$mapped) {
                                    error_log("WARNING: failed to map $e's NF postal address $oneliner in home postcode {$location['ul_PostCode']}");
                                    $address_failed++;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

