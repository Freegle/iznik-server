<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/misc/Stats.php');

$opts = getopt('f:t:');

if (count($opts) < 2) {
    echo "Usage: hhvm group_merge -f <shortname of source group> -t <short name of destination group>\n";
} else {
    $from = $opts['f'];
    $to = $opts['t'];
    $g = Group::get($dbhr, $dbhm);

    $srcid = $g->findByShortName($from);
    $dstid = $g->findByShortName($to);
    $dstg = Group::get($dbhr, $dbhm, $dstid);

    $moved = 0;
    $alreadys = 0;

    if ($srcid && $dstid) {
        # First move the members.
        error_log("Move members");
        $membs = $dbhr->preQuery("SELECT * FROM memberships WHERE groupid = ?;", [ $srcid ]);
        foreach ($membs as $memb) {
            $membs2 = $dbhr->preQuery("SELECT * FROM memberships WHERE groupid = ? AND userid = ?;", [$dstid, $memb['userid']]);
            $already = count($membs2) > 0;
            if (!$already) {
                $dbhm->preQuery("UPDATE memberships SET groupid = ? WHERE groupid = ? AND userid = ?;", [$dstid, $srcid, $memb['userid']]);

                if ($dstg->getPrivate('onyahoo')) {
                    $u = new User($dbhr, $dbhm, $memb['userid']);
                    $u->triggerYahooApplication($dstid, FALSE);
                }

                $moved++;

                if ($moved % 1000 === 0) {
                    error_log("...$moved");
                }
            } else {
                $alreadys++;
            }
        }

        # Move the key other stuff over.
        foreach (['communityevents_groups', 'messages_groups', 'messages_history', 'messages_postings', 'newsfeed', 'polls', 'shortlinks', 'users_banned', 'users_comments', 'volunteering_groups'] AS $table) {
            error_log("Update $table");
            $dbhm->preExec("UPDATE IGNORE $table SET groupid = $dstid WHERE groupid = $srcid");
        }

        # Hide the old group.
        $dbhm->preExec("UPDATE groups SET publish = 0, onmap = 0 WHERE id = $srcid;");

        # Regenerate the stats on the group.
        $i = 0;
        do {
            $date = date('Y-m-d', strtotime("$i days ago"));
            error_log("Gen stats for $date");
            $s = new Stats($dbhr, $dbhm, $dstid);
            $s->generate($date);
            $i--;
        } while ($date >= '2015-08-25');
    } else {
        error_log("Groups not found");
    }

    error_log("Moved $moved, already member $alreadys");
    $dbhm->preExec("DELETE FROM memberships WHERE groupid = ?;", [ $srcid ]);
}
