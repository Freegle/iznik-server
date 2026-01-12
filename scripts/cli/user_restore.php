<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$opts = getopt('e:b:p:u:w:');

$useridkeywords = [
    'userid',
    'user',
    'byuserid',
    'user1',
    'user2'
];

if (!isset($opts['e'])) {
    echo "Usage: php user_restore.php -e <email to restore> [-b <backup_host>] [-p <backup_port>] [-u <backup_user>] [-w <backup_password>]\n";
    echo "\n";
    echo "Options:\n";
    echo "  -e  Email address of user to restore (required)\n";
    echo "  -b  Backup database host (default: localhost)\n";
    echo "  -p  Backup database port (default: 3309)\n";
    echo "  -u  Backup database user (default: from config)\n";
    echo "  -w  Backup database password (default: from config)\n";
    echo "\n";
    echo "Example for yesterday system:\n";
    echo "  php user_restore.php -e user@example.com -b yesterday.ilovefreegle.org -p 3306 -u root -w <password>\n";
    exit(1);
} else {
    $backupHost = $opts['b'] ?? 'localhost';
    $backupPort = $opts['p'] ?? '3309';
    $backupUser = $opts['u'] ?? $dbconfig['user'];
    $backupPass = $opts['w'] ?? $dbconfig['pass'];

    $dbhback = new LoggedPDO("$backupHost:$backupPort", $dbconfig['database'], $backupUser, $backupPass, TRUE);
    $email = $opts['e'];
    $ulive = User::get($dbhr, $dbhm);
    $luid = $ulive->findByEmail($email);
    $ulive = User::get($dbhr, $dbhm, $luid);
    error_log("User on live #$luid");

    if (!$luid) {
        error_log("...create");
        $luid = $ulive->create(NULL, NULL, NULL);
        $ulive->addEmail($email);
    }

    $uback = new User($dbhback, $dbhback);
    $buid = $uback->findByEmail($email);
    $uback = new User($dbhback, $dbhback, $buid);
    error_log("User on backup #$buid");

    if ($luid && $buid) {
        # User attributes
        foreach (['fullname', 'firstname', 'lastname', 'yahooid', 'systemrole', 'permissions'] as $att) {
            $val = $uback->getPrivate($att);
            try {
                $ulive->setPrivate($att, $val);
            } catch (\Exception $e) {
                error_log($e->getMessage());
                \Sentry\captureException($e);
            }
        }

        $ulive->setPrivate('deleted', NULL);
        $ulive->setPrivate('forgotten', NULL);

        # Tables with foreign keys.
        foreach ([
            'memberships' => [ 'userid' ],
            'spam_users' => [ 'userid', 'byuserid' ],
            'users_banned' => [ 'userid' ],
            'users_donations' => [ 'userid' ],
            'microactions' => [ 'userid' ],
            'giftaid' => [ 'userid' ],
            'users_logins' => [ 'userid' ],
            'users_emails' => [ 'userid' ],
            'users_comments' => [ 'userid', 'byuserid' ],
            'sessions' => [ 'userid' ],
            'messages' => [ 'fromuser' ],
            'users_push_notifications' => [ 'userid' ],
            'users_notifications' => [ 'fromuser', 'touser' ],
            'chat_rooms' => [ 'user1', 'user2' ],
            'chat_roster' => [ 'userid' ],
            'chat_messages' => [ 'userid' ],
            'users_searches' => [ 'userid' ],
            'memberships_history' => [ 'userid' ],
            'logs' => [ 'user' ],
            'logs_sql' => [ 'userid' ],
            'newsfeed' => [ 'userid' ]
                 ] as $table => $keys) {
            foreach ($keys as $key) {
                error_log("Table $table key $key");
                $rows = $dbhback->preQuery("SELECT * FROM $table WHERE $key = ?;", [ $buid ]);

                foreach ($rows as $row) {
                    if ($table == 'users_emails') {
                        # This is generated so we must not provide it
                        unset($row['md5hash']);
                    }

                    if ($table == 'chat_messages' || $table == 'chat_roster') {
                        # The roomid may be different.
                        $roomid = $row['chatid'];
                        $oldrooms = $dbhback->preQuery("SELECT * FROM chat_rooms WHERE id = ?;", [
                            $roomid
                        ]);

                        foreach ($oldrooms as $oldroom) {
                            error_log("Old chat {$oldroom['chattype']}, {$oldroom['groupid']}, {$oldroom['user1']}, {$oldroom['user2']}");
                            $sql = "SELECT * FROM chat_rooms WHERE chattype = '{$oldroom['chattype']}' ";

                            if (Utils::pres('groupid', $oldroom)) {
                                $sql .= "AND groupid = {$oldroom['groupid']} ";
                            }

                            if (Utils::pres('user1', $oldroom)) {
                                $i = $oldroom['user1'] == $buid ? $luid : $oldroom['user1'];
                                $sql .= " AND user1 = $i";
                            }

                            if (Utils::pres('user2', $oldroom)) {
                                $i = $oldroom['user2'] == $buid ? $luid : $oldroom['user2'];
                                $sql .= " AND user2 = $i";
                            }

                            $newrooms = $dbhr->preQuery($sql);

                            foreach ($newrooms as $newroom) {
                                error_log("Found new room $roomid");
                                $roomid = $newroom['id'];
                            }
                        }

                        $row['chatid'] = $roomid;
                    }

                    error_log("  #{$row['id']}");

                    # The row might or might not exist.
                    #unset($row['id']);
                    $sql1 = "INSERT INTO $table (";
                    $sql2 = ") VALUES (";
                    $sql3 = ") ON DUPLICATE KEY UPDATE $key = $luid, id = LAST_INSERT_ID(id)";
                    $first = TRUE;
                    $vals = [];
                    $vals2 = [];
                    foreach ($row as $key2 => $val) {
                        if (!is_int($key2)) {
                            if (!$first) {
                                $sql1 .= ", ";
                                $sql2 .= ", ";
                            }

                            $first = FALSE;
                            $sql1 .= $key2;
                            $sql2 .= "?";
                            $sql3 .= ", $key2 = ?";

                            #error_log("Consider $key2 => $val vs $buid");
                            $val = ($key2 == $key || (in_array($key2, $useridkeywords) && $val == $buid)) ? $luid : $val;
                            #error_log("...$val");
                            $vals[] = $val;
                            $vals2[] = $val;
                        }
                    }

                    $sql = "$sql1 $sql2 $sql3";
                    $v = array_merge($vals, $vals2);
                    #error_log($sql . var_export($v, TRUE));
                    try {
                        $rc = $dbhm->preExec($sql, $v);
                        error_log("Inserted " . $dbhm->lastInsertId());
                    } catch (\Exception $e) {
                        error_log($e->getMessage());
                        \Sentry\captureException($e);
                    }
                    #error_log("Returned $rc");
                    #exit(0);
                }
            }
        }

        # Undelete messages and re-add to groups.
        error_log("Undelete messages");
        $msgs = $dbhback->preQuery("SELECT id, deleted FROM messages WHERE fromuser = ?", [
            $buid
        ]);

        foreach ($msgs as $msg) {
            error_log("...{$msg['id']}");
            $dbhm->preExec("UPDATE messages SET deleted = ? WHERE id = ?;", [
                $msg['deleted'],
                $msg['id']
            ]);

            $groups = $dbhback->preQuery("SELECT * FROM messages_groups WHERE msgid = ?;", [
                $msg['id']
            ]);

            foreach ($groups as $group) {
                error_log("...group {$group['groupid']}");
                $dbhm->preExec("UPDATE messages_groups SET deleted = ?, arrival = ? WHERE msgid = ? AND groupid = ?;", [
                    $group['deleted'],
                    $group['arrival'],
                    $group['msgid'],
                    $group['groupid']
                ]);
            }
        }
    }
}
