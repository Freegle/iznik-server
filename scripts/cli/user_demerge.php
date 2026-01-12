<?php

# Run on backup server to demerge a user on live using data from the backup.  Use with astonishing levels of caution.
#
# Once you've done this, you can then run user_restore to put the demerged user back.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$dbhback = new LoggedPDO('localhost:3309', $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE);

$opts = getopt('e:f:');

$useridkeywords = [
    'userid',
    'user',
    'byuserid',
    'user1',
    'user2'
];

if (count($opts) < 1) {
    echo "Usage: php user_demerge.php -e <email to demerge> -f <email to demerge from>\n";
} else {
    $email = $opts['e'];
    $fromemail = $opts['f'];

    $uback = new User($dbhback, $dbhback);
    $buid = $uback->findByEmail($email);
    $fuid = $uback->findByEmail($fromemail);
    $uback = new User($dbhback, $dbhback, $buid);
    error_log("User to demerge #$buid from #$fuid");

    if ($buid && $fuid) {
        # Tables with foreign keys.  We want to delete the entry on the live system if it has the same id as the
        # entry on the backup system, because then it will be one which has been combined in a merge.
        foreach ([
                     'memberships' => [ 'userid' ],
                     'spam_users' => [ 'userid', 'byuserid' ],
                     'users_banned' => [ 'userid' ],
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
                    $liverows = $dbhr->preQuery("SELECT * FROM $table WHERE $key = ? AND id = ?", [ $fuid, $row['id'] ]);

                    foreach ($liverows as $liverow) {
                        # This has the same id on the live system for the merged user as it does on the backup for the
                        # user we are trying to demerge.  So we should remove it from the merged user, because it
                        # isn't theirs.
                        error_log("...delete $table {$row['id']}");
                        $dbhm->preExec("DELETE FROM $table WHERE id = ?", [
                            $row['id']
                        ]);
                    }
                }
            }
        }

        $yid = $uback->getPrivate('yahooid');

        if ($yid) {
            $dbhm->preExec("UPDATE users SET yahooid = NULL WHERE yahooid = ?", [
                $yid
            ]);
        }
    }
}
