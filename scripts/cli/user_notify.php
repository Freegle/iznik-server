<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:t:u:g:l:x:');

if (count($opts) < 2) {
    echo "Usage: php user_notify.php (-i <user ID> or -g <group ID>) -t <type> (-u url) (-l title -x text)\n";
} else {
    $id = Utils::presdef('i', $opts, NULL);
    $gid = Utils::presdef('g', $opts, NULL);
    $type = $opts['t'];
    $url = Utils::presdef('u', $opts, NULL);
    $title = Utils::presdef('l', $opts, NULL);
    $text = Utils::presdef('x', $opts, NULL);

    $n = new Notifications($dbhr, $dbhm);

    if ($id) {
        $added = $n->add(NULL, $id, $type, NULL, NULL, $url, $title, $text);
        error_log("Added #$added");
    } else if ($gid) {
        if ($gid == -1) {
            $membs = $dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid IN (SELECT id FROM groups WHERE type = 'Freegle' AND publish = 1 AND onhere = 1) AND collection = ?;", [
                MembershipCollection::APPROVED
            ]);
        } else {
            $membs = $dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid = ? AND collection = ?;", [
                $gid,
                MembershipCollection::APPROVED
            ]);
        }

        $sendcount = 0;
        $skipcount = 0;
        $alreadycount = 0;

        foreach ($membs as $memb) {
            $u = new User($dbhr, $dbhm, $memb['userid']);

            if ($type == Notifications::TYPE_TRY_FEED || $type == Notifications::TYPE_ABOUT_ME) {
                # Don't send these too often.  Helps when notifying multiple groups
                $mysqltime = date("Y-m-d H:i:s", strtotime("midnight 7 days ago"));
                $recent = $dbhr->preQuery("SELECT * FROM users_notifications WHERE touser = ? AND type = ? AND timestamp > '$mysqltime';", [
                    $memb['userid'],
                    $type
                ]);

                if (count($recent) > 0) {
                    error_log($u->getEmailPreferred() . "..already");
                    $alreadycount++;
                    continue;
                }
            }

            $send = FALSE;
            $emails = $u->getEmails();
            foreach ($emails as $email) {
                if (Mail::ourDomain($email['email'])) {
                    $send = TRUE;
                }
            }

            if ($send) {
                error_log($u->getEmailPreferred() . "...send");
                $n->add(NULL, $memb['userid'], $type, NULL, NULL, $url, $title, $title);
                $sendcount++;
            } else {
                error_log($u->getEmailPreferred() . "..skip");
                $skipcount++;
            }
        }

        error_log("Sent to $sendcount, skipped $skipcount, already $alreadycount");
    }
}
