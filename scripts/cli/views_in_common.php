<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$recent = date('Y-m-d', strtotime("90 days ago"));

//$candidates = $dbhr->preQuery("SELECT COUNT(*) AS count, msgid FROM messages_views WHERE timestamp >= '$recent' GROUP BY msgid HAVING count > 1 ORDER BY count DESC;");
//
//foreach ($candidates as $candidate) {
//    $views = $dbhr->preQuery("SELECT messages.id, mesages.subject FROM messages_views INNER JOIN messages ON messages.id = messages_views.msgid WHERE msgid = ?", [
//        $candidate['msgid']
//    ]);
//
//    foreach ($views as $view) {
//
//    }
//}

$users = [];
$sql = "SELECT * FROM messages_likes WHERE messages_likes.timestamp >= '$recent' AND type = 'View';";
#  AND messages_outcomes.outcome IS NULL AND messages_promises.msgid IS NULL
#error_log($sql);
$msgs = $dbhr->preQuery($sql);

error_log("Candidate messages " . count($msgs));

foreach ($msgs as $msg) {
    if (!Utils::pres($msg['userid'], $users)) {
        $users[$msg['userid']] = [ $msg['msgid']];
    } else {
        array_push($users[$msg['userid']], $msg['msgid']);
    }
}

error_log("Candidate users " . count($users));

foreach ($users as $user1 => $msgs1) {
    foreach ($users as $user2 => $msgs2) {
        $intersect = array_intersect($msgs1, $msgs2);
        $diff = array_diff($msgs1, $msgs2);

        if ($user1 > $user2 && count($intersect) > 3 && count($diff) > 0) {
            $mods = $dbhr->preQuery("SELECT id FROM users WHERE id IN (?, ?) AND users.systemrole IN ('Moderator', 'Support', 'Admin');", [
                $user1,
                $user2
            ]);

            if (count($mods) >= 0) {
                $u1 = new User($dbhr, $dbhm, $user1);
                $u2 = new User($dbhr, $dbhm, $user2);
                $shown = FALSE;
                
                foreach ($diff as $msgid) {
                    $diffmsgs = $dbhr->preQuery("SELECT subject, outcome, promisedat FROM messages LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id LEFT JOIN messages_promises ON messages_promises.msgid = messages.id WHERE messages.id = ? HAVING outcome IS NULL AND promisedat IS NULL;", [
                        $msgid
                    ]);

                    foreach ($diffmsgs as $diffmsg) {
                        $views = $dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_likes WHERE msgid = ?;", [
                            $msgid
                        ]);

                        if ($views[0]['count'] > 1) {
                            $diffmsg1 = !in_array($msgid, $msgs1) && count($diff) / count($msgs1) <= 0.25;
                            $diffmsg2 = !in_array($msgid, $msgs2) && count($diff) / count($msgs2) <= 0.25;

                            if (!$shown && ($diffmsg1 || $diffmsg2)) {
                                error_log($u1->getEmailPreferred() . " (" . $u1->getPrivate('systemrole') . ") and " . $u2->getEmailPreferred() . " (" . $u2->getPrivate('systemrole') . ") viewed:");

                                foreach ($intersect as $msgid) {
                                    $msg = $dbhr->preQuery("SELECT subject FROM messages WHERE id = ?;", [
                                        $msgid
                                    ]);

                                    error_log("...{$msg[0]['subject']}");
                                }

                                $shown = TRUE;
                            }

                            if ($diffmsg1) {
                                error_log("......suggest {$diffmsg['subject']} to " . $u1->getEmailPreferred());
                            }

                            if ($diffmsg2) {
                                error_log("......suggest {$diffmsg['subject']} to " . $u2->getEmailPreferred());
                            }
                        }
                    }
                }
            }
        }
    }
}