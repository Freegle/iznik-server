<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/misc/Location.php');
require_once(IZNIK_BASE . '/include/misc/PAF.php');

# Stats
$users_known = 0;
$users_new = 0;
$users_noemail = 0;
$postcode_mapped = 0;
$postcode_untouched = 0;
$postcode_failed = 0;
$address_mapped = 0;
$address_failed = 0;

# User filter for testing this before we go live.
$userfilt = " AND u_Id IN (9, 11, 54) ";
$userfilt = " AND u_Moderator = 1 ";
$userfilt = "";

# Whether we're doing a test migration i.e. no actual data change.
$test = TRUE;

$dsn = "mysql:host={$dbconfig['host']};dbname=Norfolk;charset=utf8";

$dbhn = new LoggedPDO($dsn, $dbconfig['user'], $dbconfig['pass'], array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => FALSE
));

# Look for users active in the last 3 years.
$start = date('Y-m-d', strtotime("3 years ago"));
$sql = "SELECT * FROM u_User WHERE u_Id IN (SELECT DISTINCT u_Id FROM u_User LEFT JOIN pr_PostResponder ON pr_PostResponder.pr_u_Id_Responder = u_User.u_Id LEFT JOIN p_Post ON p_Post.p_u_Id = u_User.u_Id WHERE u_IsActive = 1 AND u_IsActivated = 1 AND (p_DatePosted >= '$start' OR pr_LastUpdatedDt >= '$start'));";
$users = $dbhn->preQuery($sql);
$total = count($users);
$count = 0;
$u = new User($dbhr, $dbhm);

error_log("Migrate $total users\n");

# First migrate across all the users.
foreach ($users as $user) {
    if ($user['u_NickName'] != 'System') {
        error_log("{$user['u_NickName']}");

        # Get email.  Use the most recent.
        # For real DB.  $sql = "SELECT * FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 AND ue_IsActivated = 1 AND ue_AddressProblem = 0 ORDER BY ue_ModifiedDt DESC LIMIT 1;";
        $sql = "SELECT * FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 ORDER BY ue_ModifiedDt DESC LIMIT 1;";
        $emails = $dbhn->preQuery($sql, [
            $user['u_Id']
        ]);

        if (count($emails)) {
            foreach ($emails as $email) {
                $e = str_replace('.NERFED', '', $email['ue_EmailAddress']);
                $uid = $u->findByEmail($e);

                if ($uid) {
                    # The user already exists.  Don't touch them - if they're actively using FD already then we don't
                    # want to confuse matters by changing their login.
                    $users_known++;
                    error_log("...found $e as #$uid, leave untouched.");
                } else {
                    $users_new++;

                    if ($test) {
                        error_log("...$e not found, would create");
                    } else {
                        $uid = $u->create(NULL, NULL, $user['u_NickName']);
                        $u->addEmail($e);
                        error_log("...$e not found, created as #$uid");

                        # Add a login for them.  Directly since we have the password hashed already.
                        $rc = $dbhm->preExec("INSERT INTO users_logins (userid, uid, type, credentials, salt) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE credentials = ?, salt = ?;",
                            [$uid, $uid, User::LOGIN_NATIVE, $user['u_Password'], $user['u_PasswordSalt'], $user['u_Password'], $user['u_PasswordSalt']]);
                    }
                }
            }
        } else {
            error_log("ERROR: No email for Norfolk user {$user['u_Id']}, not migrating.");
            $users_noemail++;
        }
    }
}

# Locations
error_log("\nMigrate locations\n");
$sql = "SELECT * FROM u_User WHERE u_IsActive = 1 AND u_IsActivated = 1 $userfilt;";
$users = $dbhn->preQuery($sql);
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

            error_log("$e locations:");
            # Use the most recent location.  Some users have multiple - we have agreed not to care.
            $locations = $dbhn->preQuery("SELECT * FROM ul_UserLocation WHERE ul_u_Id = ? AND ul_Primary = 1 AND ul_Active = 1 ORDER BY ul_ModifiedDt DESC LIMIT 1;", [
                $user['u_Id']
            ]);

            foreach ($locations as $location) {
                # Set the user's location to be that postcode.
                $l = new Location($dbhr, $dbhm);
                $locs = $l->typeahead($location['ul_PostCode'], 1, TRUE);

                if (count($locs)) {
                    $near = $locs[0]['groupsnear'];
                    if (count($near)) {
                        error_log("...{$location['ul_PostCode']} found, within FD community {$near[0]['nameshort']}");

                        $u = new User($dbhr, $dbhm);
                        $settings = $u->getPrivate('settings');
                        $settings = json_decode($settings, TRUE);

                        if (pres('mylocation', $settings)) {
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
                    } else {
                        error_log("ERROR: Can't map NF postcode {$location['ul_PostCode']} to a community");
                        $postcode_failed++;
                    }
                } else {
                    error_log("ERROR: Can't map NF postcode {$location['ul_PostCode']} to an FD postcode");
                    $postcode_failed++;
                }

                if (pres('ul_Address', $location)) {
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
                                        $aid = $a->create($uid, $fdloc['id'], presdef('ul_Directions', $location, NULL));
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

error_log("\n\nSummary:\n\n");
error_log("$users_new new users, $users_known already known.");
error_log("$postcode_mapped postcodes mapped, $postcode_failed postcodes failed to map, $postcode_untouched already different on FD");
error_log("$address_mapped addresses mapped, $address_failed addresses failed to map");

# TODO
# - groups
# - group memberships
# - email digest settings inc not causing mail flood