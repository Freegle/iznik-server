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

# First migrate across all the users.
foreach ($users as $user) {
    if ($user['u_NickName'] != 'System') {
        error_log("{$user['u_Id']} {$user['u_NickName']}");

        # Get email.  Use the most recent.
        $sql = "SELECT * FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 AND ue_IsActivated = 1 AND ue_AddressProblem = 0 ORDER BY ue_ModifiedDt DESC LIMIT 1;";
        #$sql = "SELECT * FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 ORDER BY ue_ModifiedDt DESC LIMIT 1;";
        $emails = $dbhn->preQuery($sql, [
            $user['u_Id']
        ]);

        if (count($emails)) {
            $uid = NULL;

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

            if ($uid && $user['u_OnHoliday']) {
                error_log("...on holiday");
                if (!$test) {
                    $till = date('Y-m-d', strtotime("+30 days"));
                    $dbhm->preExec("UPDATE users SET onholidaytill = ? WHERE id = ?;", [
                        $till,
                        $uid
                    ]);
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

                                # We are leaving event/volops on to re-engage.
//                                if (!$test) {
//                                    $u->setMembershipAtt($gid, 'eventsallowed', 0);
//                                    $u->setMembershipAtt($gid, 'volunteeringallowed', 0);
//                                }
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

# Get the posts, including by users we're not migrating, for stats purposes.
$posts = $dbhn->preQuery("SELECT DISTINCT ue_EmailAddress, p_Post.p_Id, p_Post.p_DatePosted, p_Post.p_DateClosed, p_Post.p_u_Id, mp_PostStatus.mp_Status, mp_PostStatus.mp_Desc,
       mc_Condition.mc_Desc, p_Post.p_ShortDesc, p_Post.p_Description, mt_PostType.mt_Type,
       ul_PostCode, ul_Longitude, ul_Latitude
FROM p_Post
INNER JOIN u_User ON u_User.u_Id = p_Post.p_u_Id $userfilt
INNER JOIN mt_PostType ON mt_PostType.mt_Id = p_Post.p_mt_PostType
INNER JOIN mp_PostStatus ON mp_PostStatus.mp_Id = p_Post.p_mp_Id
LEFT JOIN mc_Condition ON mc_Condition.mc_Condition = p_Post.p_mc_Condition
LEFT JOIN ue_UserEmail ON ue_UserEmail.ue_u_Id = p_Post.p_u_Id
LEFT JOIN pl_PostLocation ON pl_PostLocation.pl_p_Id = p_Post.p_Id
LEFT JOIN ul_UserLocation ON pl_PostLocation.pl_ul_Id = ul_UserLocation.ul_Id
WHERE mp_PostStatus.mp_Status IN ('o', 'a', 'c')
ORDER BY p_DatePosted DESC");

$postcount = 0;
$photocount = 0;
$postfail = 0;
$withdrawn = 0;
$taken = 0;
$received = 0;

$u = new User($dbhr, $dbhm);

foreach ($posts as $post) {
    error_log("{$post['p_Id']} {$post['ue_EmailAddress']} {$post['p_ShortDesc']}");
    $mid = NULL;
    $postcount++;

    # See if we've migrated already.
    $msgs = $dbhm->preQuery("SELECT id FROM messages WHERE messageid = ?;", [
        "Norfolk-{$post['p_Id']}"
    ]);

    if (count($msgs)) {
        error_log("...already migrated");
        $mid = $msgs[0]['id'];
    } else {
        error_log("...new message");
        $uid = $u->findByEmail($post['ue_EmailAddress']);

        if ($uid) {
            error_log("...known user $uid");
        } else {
            error_log("...unknown user");
        }

        $pc = $post['ul_PostCode'];

        if ($pc) {
            # Construct subject
            $subj = "{$post['mt_Type']}: {$post['p_ShortDesc']}";

            $l = new Location($dbhr, $dbhm);
            $lid = $l->findByName($pc);
            $l = new Location($dbhr, $dbhm, $lid);
            $areaid = $l->getPrivate('areaid');
            $l2 = new Location($dbhr, $dbhm, $areaid);
            $loc = $l2->getPrivate('name') . ' ' . substr($pc, 0, strpos($pc, ' '));
            $subj = "$subj ($loc)";

            error_log("...$subj");

            # Find the community we would have posted this on.
            $groups = $dbhr->preQuery("SELECT id, nameshort FROM groups WHERE ST_Contains(polyindex, GeomFromText('POINT({$post['ul_Longitude']} {$post['ul_Latitude']})'));");
            if (count($groups)) {
                error_log("...on {$groups[0]['nameshort']}");
                $gid = $groups[0]['id'];

                if (!$test) {
                    # Create a message.
                    $rc = $dbhm->preExec("INSERT INTO messages (messageid, arrival, date, fromuser, fromaddr, subject, textbody, type) VALUES(?, ?, ?, ?, ?, ?, ?, ?);", [
                        "Norfolk-{$post['p_Id']}",
                        $post['p_DatePosted'],
                        $post['p_DatePosted'],
                        $uid,
                        $post['ue_EmailAddress'],
                        $subj,
                        $post['p_Description'] . ($post['mc_Desc'] ? "\n\n{$post['mc_Desc']}" : ''),
                        $post['mt_Type'] == 'Offer' ? Message::TYPE_OFFER : Message::TYPE_WANTED
                    ]);

                    $mid = $dbhm->lastInsertId();

                    # Add it to the group.
                    $dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, msgtype) VALUES (?, ?, ?, ?, ?);", [
                        $mid,
                        $gid,
                        MessageCollection::APPROVED,
                        $post['p_DatePosted'],
                        $post['mt_Type'] == 'Offer' ? Message::TYPE_OFFER : Message::TYPE_WANTED
                    ]);

                    # Add any images.
                    $images = $dbhn->preQuery("SELECT * FROM pi_PostImage WHERE pi_p_Id = ?;", [
                        $post['p_Id']
                    ]);

                    foreach ($images as $image) {
                        error_log("...image {$image['pi_Filename']}");
                        $data = @file_get_contents('/tmp/PostImages/' . $image['pi_Filename']);

                        if ($data) {
                            $a = new Attachment($dbhr, $dbhm);
                            $aid = $a->create($mid, 'image/jpg', $data);

                            # Archive so we don't flood the DB.
                            $a->archive();
                        }
                    }

                }
            } else {
                error_log("...couldn't find group");
                $postfail;
            }

        } else {
            $postfail++;
        }
    }

    if ($mid) {
        # Update any outcomes, including for existing messages.
        $dbhm->preExec("DELETE FROM messages_outcomes WHERE msgid = ?;", [
            $mid
        ]);

        if ($post['mp_Status'] == 'W') {
            // Withdrawn
            error_log("...withdrawn");
            $dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments, timestamp) VALUES (?,?,?,?,?);", [
                $mid,
                Message::OUTCOME_WITHDRAWN,
                NULL,
                NULL,
                $post['p_DateClosed']
            ]);
            $withdrawn++;
        } else if ($post['mp_Status'] == 'C') {
            // Complete
            $outcome = $post['mt_Type'] == 'Offer' ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;
            error_log("...$outcome");
            if ($outcome == 'Taken') {
                $taken++;
            } else {
                $received++;
            }

            $dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments, timestamp) VALUES (?,?,?,?,?);", [
                $mid,
                $outcome,
                NULL,
                NULL,
                $post['p_DateClosed']
            ]);
        } else if ($post['mp_Status'] == 'O') {
            // Open - need to look at pr_PostResponder.
            $prs = $dbhn->preQuery("SELECT * FROM pr_PostResponder INNER JOIN mpr_PostResponderStatus ON mpr_PostResponderStatus.mpr_Id = pr_PostResponder.pr_mpr_Id WHERE pr_p_Id = ?;", [
                $post['p_Id']
            ]);

            $completed = FALSE;

            foreach ($prs as $pr) {
                if ($pr['mpr_Desc'] === 'Accepted' || $pr['mpr_Desc'] === 'Completed') {
                    // Successful.
                    $outcome = $post['mt_Type'] == 'Offer' ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;
                    error_log("...$outcome");

                    if ($outcome == 'Taken') {
                        $taken++;
                    } else {
                        $received++;
                    }

                    $dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments, timestamp) VALUES (?,?,?,?,?);", [
                        $mid,
                        $outcome,
                        NULL,
                        NULL,
                        $pr['pr_LastUpdatedDt']
                    ]);
                }
            }
        }
    }
}

# Message items, which we need to have set up for stats.  Look for any without items.
$items  = $dbhr->preQuery("SELECT messages.subject, messages_groups.msgid FROM messages_groups LEFT JOIN messages_items ON messages_items.msgid = messages_groups.msgid INNER JOIN messages ON messages.id = messages_groups.msgid WHERE groupid IN (
515504,
515507,
515510,
515513,
515516,
515519,
515522,
515525,
515528,
515531,
515534,
515537,
515540,
515543,
515546,
515549,
515552
) AND messages_items.itemid IS NULL");
$total = count($items);
$count = 0;

foreach ($items AS $item) {
    if (preg_match("/(.+)\:(.+)\((.+)\)/", $item['subject'], $matches)) {
        $itemname = trim($matches[2]);
        error_log("...{$item['msgid']} $itemname");

        if ($item) {
            $i = new Item($dbhr, $dbhm);
            $id = $i->create($itemname);
            $dbhm->preExec("INSERT INTO messages_items (msgid, itemid) VALUES (?,?);", [
                $item['msgid'],
                $id
            ]);
        }
    }
}

# Migrate recently active messages into Chat
$messages = $dbhn->preQuery("SELECT g_Message.*, p_Post.p_u_Id FROM pr_PostResponder LEFT JOIN p_Post ON pr_PostResponder.pr_p_Id = p_Post.p_Id INNER JOIN g_Message ON g_Message.g_pr_Id = pr_PostResponder.pr_Id WHERE pr_PostResponder.pr_CreatedDt BETWEEN '2020-02-01' AND NOW() ORDER BY g_Message.g_Id ASC;");
$u = new User($dbhr, $dbhm);

foreach ($messages as $message) {
    # Find email address of user this is to.
    $touid = NULL;
    $fromuid = NULL;

    $tos = $dbhn->preQuery("SELECT ue_EmailAddress FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 AND ue_IsActivated = 1 AND ue_AddressProblem = 0;", [
        $message['g_u_IdTo']
    ]);

    foreach ($tos as $to) {
        $touid = $u->findByEmail($to['ue_EmailAddress']);
    }

    if ($message['g_u_IdFrom']) {
        $froms = $dbhn->preQuery("SELECT ue_EmailAddress FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 AND ue_IsActivated = 1 AND ue_AddressProblem = 0;", [
            $message['g_u_IdFrom']
        ]);

        foreach ($froms as $from) {
            $fromuid = $u->findByEmail($from['ue_EmailAddress']);
        }
    } else if ($message['p_u_Id']) {
        $froms = $dbhn->preQuery("SELECT ue_EmailAddress FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 AND ue_IsActivated = 1 AND ue_AddressProblem = 0;", [
            $message['p_u_Id']
        ]);

        foreach ($froms as $from) {
            $fromuid = $u->findByEmail($from['ue_EmailAddress']);
        }
    }

    if ($touid && $fromuid) {
        error_log("...message {$message['g_Id']} to #$touid from #$fromuid");
        $r = new ChatRoom($dbhr, $dbhm);
        $rid = $r->createConversation($fromuid, $touid);
        $r = new ChatRoom($dbhr, $dbhm, $rid);

        if ($rid) {
            $cm = new ChatMessage($dbhr, $dbhm);
            $html = new \Html2Text\Html2Text($message['g_Content']);
            $textbody = $html->getText();
            #error_log($textbody);

            if (preg_match('/(.|\n)*\_((.|\n)*)\_(.|\n)*/',$textbody, $matches)) {
                #error_log("Matches " . var_export($matches, TRUE));
                $textbody = $matches[2];
            }

            $textbody = preg_replace('/To enter your rating(.|\n)*\]/', '', $textbody);
            $textbody = trim($textbody);

            $already = $dbhr->preQuery("SELECT id FROM chat_messages WHERE norfolkmsgid = ?;", [
                $message['g_Id']
            ]);

            if (count($already)) {
                error_log("...already migrated, replace");
                $mid = $already[0]['id'];
                $dbhm->preExec("UPDATE chat_messages SET message = ? WHERE id = ?;", [
                    $mid,
                    $textbody
                ]);


            } else {
                error_log("...new message");
                $dbhm->preExec("INSERT INTO chat_messages (chatid, userid, type, date, message, seenbyall, mailedtoall, norfolkmsgid) VALUES (?, ?, ?, ?, ?, ?, ?, ?);", [
                    $rid,
                    $fromuid,
                    ChatMessage::TYPE_DEFAULT,
                    $message['g_Date'],
                    $textbody,
                    1,
                    1,
                    $message['g_Id']
                ]);

                $mid = $dbhm->lastInsertId();
            }

            # Ensure we're up to date.
            $r->upToDate($fromuid);
            $r->upToDate($touid);
            $r->replyTimes([$fromuid, $touid], TRUE);
        }
    } else {
        error_log("...message {$message['g_Id']} can't find users, to #$touid from #$fromuid");
    }
}

error_log("\n\nSummary:\n\n");
error_log("$users_new new users, $users_known already known.");
error_log("$emaildaily daily emails, $emailimmediate immediate, $emailnever never");
error_log("$postcode_mapped postcodes mapped, $postcode_failed postcodes failed to map, $postcode_untouched already different on FD");
error_log("$address_mapped addresses mapped, $address_failed addresses failed to map");
error_log("Posts migrated $postcount, photos $photocount, failed $postfail, taken $taken, received $received, withdrawn $withdrawn");
