<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$sql = "SELECT id FROM `groups` WHERE `type` = ? AND onhere = 1 AND publish = 1 ORDER BY nameshort ASC;";

$groups = $dbhr->preQuery($sql, [
    Group::GROUP_FREEGLE
]);

$count = 0;
$skipped = 0;

const ACTIVE_LIMIT = 365;

foreach ($groups as $group) {
    $g = new Group($dbhr, $dbhm, $group['id']);
    error_log($g->getName());

    # Check that we have an approval within the relevant time period days.  No point alerting about inactive mods on inactive groups.
    $mysqltime = date("Y-m-d", strtotime(ACTIVE_LIMIT . " days ago"));
    $approvals = $dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_groups WHERE arrival >= ? AND groupid = ? AND approvedby IS NOT NULL;", [
        $mysqltime,
        $group['id']
    ]);

    if (count($approvals)) {
        $mods = $g->getMods();

        foreach ($mods as $mod) {
            # Check if we've warned about them recently.
            $warned = $dbhr->preQuery("SELECT * FROM groups_mods_welfare WHERE modid = ? AND (groupid IS NULL OR groupid = ?) AND (state = 'Ignore' OR DATEDIFF(NOW(), warnedat) <= 31);", [
                $mod,
                $group['id']
            ]);

            if (count($warned)) {
                $skipped++;
                continue;
            }

            # Check if active.
            $u = new User($dbhr, $dbhm, $mod);
            $email = $u->getEmailPreferred();
            $name = $u->getName();

            if ($u->activeModForGroup($group['id'])) {
                # We'd expect them to be active on this group.  But it's probably sufficient that they are active on
                # some group, because then they are still engaged as a volunteer.
                $approved = $dbhr->preQuery("SELECT COUNT(*) AS acted, DATEDIFF(NOW(), MAX(arrival)) AS activeago FROM messages_groups WHERE approvedby = ?;", [ $mod ] );
                $lastactive = $approved[0]['activeago'];
                $acted = $approved[0]['acted'];

                if (!$acted || $lastactive > ACTIVE_LIMIT) {
                    # A mod that has not been active in the last week.  We want to notify about them.
                    error_log("...inactive mod $name ($email) last active $lastactive days ago");

                    $notify = $g->getModsToNotify();

                    foreach ($notify as $n) {
                        $m = User::get($dbhr, $dbhm, $n);
                        error_log($g->getPrivate('nameshort') . ": Mail {$m->getEmailPreferred()} about inactive mod $name ($email) last active $lastactive days ago");

                        $mail = "Please see https://discourse.ilovefreegle.org/t/safeguarding-volunteers for the background, and post any questions/issues on there.\r\n\r\n" .
                            "We are identifying volunteers on who have not actively moderated for some time, and contacting you about them because we believe you are responsible for the group.\r\n\r\n" .
                            "Initially, some of these may have been inactive for a long time. We will gradually decrease the timescale so that it will find volunteers who are usually active, but have stopped, so that you can check that they are OK.\r\n\r\n" .
                            "We think $name ($email) has not been actively moderating on {$g->getName()} for " . ($lastactive ? "$lastactive days" : "a long time") . ".\r\n\r\n" .
                            "At this point there are several things you could do:\r\n" .
                            "1. Contact them and see if they’d like to stay active, or come back.\r\n" .
                            "2. If you know that they are a backup mod, contact them and ask them to change their ModTools settings in Settings->Community->Your Settings to record them as a backup.\r\n" .
                            "3. If you know that they are no longer a mod, or it’s a duplicate account, please manually change their status to Member, from Members->Approved.\r\n" .
                            "4. Do nothing. Eventually the change to member status may happen automatically after about six months, but we'll be discussing how that works later.\r\n" .
                            "5. If there is some reason why you’d like them to be exempt from this check (e.g. if they are long-term ill) please forward this mail to geeks@ilovefreegle.org.\r\n\r\n" .
                            "We will notify you about the same volunteer no more than once a month.\r\n\r\n" .
                            "Regards,\r\n\r\n" .
                            "Freegle Geeks";

                        $message = \Swift_Message::newInstance()
                            ->setSubject("{$g->getName()}: $name doesn't seem to be active")
                            ->setFrom(NOREPLY_ADDR)
                            ->setTo($m->getEmailPreferred())
                            ->setBody($mail);

                        list ($transport, $mailer) = Mail::getMailer();
                        $mailer->send($message);

                        $dbhm->preExec("INSERT INTO groups_mods_welfare (groupid, modid) VALUES (?, ?) ON DUPLICATE KEY UPDATE warnedat = NOW();", [
                            $group['id'],
                            $mod
                        ]);
                        $count++;
                    }
                }
            }
        }
    } else {
        error_log("...inactive group");
    }
}


error_log("\nFound $count inactive mods, skipped $skipped");
Utils::unlockScript($lockh);