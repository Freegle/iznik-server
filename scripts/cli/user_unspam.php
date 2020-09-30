<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$dsn = "mysql:host=localhost;port=3309;dbname=iznik;charset=utf8";
$dbhback = new LoggedPDO($dsn, SQLUSER, SQLPASSWORD, array(
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_EMULATE_PREPARES => FALSE
));

$opts = getopt('e:');

$useridkeywords = [
    'userid',
    'user',
    'byuserid',
    'user1',
    'user2'
];

if (count($opts) < 1) {
    echo "Usage: php user_unspam.php -e <email to remove from spammer list>\n";
} else {
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

    if ($luid) {
        $uback = new User($dbhback, $dbhback);
        $buid = $uback->findByEmail($email);
        $uback = new User($dbhback, $dbhback, $buid);
        error_log("User on backup #$buid");

        if ($luid && $buid) {
            # Ensure not banned.
            error_log("...unban");
            $dbhm->preExec("DELETE FROM users_banned WHERE userid = ?;", [ $luid ]);

            # Remove from spammer list.
            error_log("...remove from spammer list");
            $dbhm->preExec("DELETE FROM spam_users WHERE userid = ?;", [ $luid ]);

            # Restore some things which will have been deleted.
            # TODO Automate via schema inspection?
            foreach ([
                         'memberships' => [ 'userid' ],
                         'messages' => [ 'fromuser' ],
                         'users_notifications' => [ 'fromuser', 'touser' ],
                         'chat_messages' => [ 'userid' ],
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
                        }

                        #error_log("Returned $rc");
                        #exit(0);
                    }
                }
            }

            # locations_excluded
        }
    }
}
