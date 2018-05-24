<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/mailtemplates/modnotif.php');

$lockh = lockScript(basename(__FILE__));

date_default_timezone_set('Europe/London');
$hour = date("H");

$mail = [];

if ($hour >= 8 && $hour <= 21)
{
    $sql = "SELECT id, nameshort FROM groups WHERE `type` = ? AND onhere = 1 AND publish = 1 ORDER BY nameshort ASC;";

    $groups = $dbhr->preQuery($sql, [
        Group::GROUP_FREEGLE
    ]);

    foreach ($groups as $group) {
        error_log("{$group['nameshort']}");
        $g = new Group($dbhr, $dbhm, $group['id']);
        $mods = $g->getMods();

        foreach ($mods as $mod) {
            # Check if active.
            $u = new User($dbhr, $dbhm, $mod);
            $email = $u->getEmailPreferred();
            $name = $u->getName();

            $approved = $dbhr->preQuery("SELECT DATEDIFF(NOW(), MAX(arrival)) AS activeago FROM messages_groups WHERE groupid = ? AND approvedby = ?;", [ $group['id'], $mod ] );
            #error_log("SELECT DATEDIFF(NOW(), MAX(arrival)) AS activeago FROM messages_groups WHERE groupid = {$group['id']} AND approvedby = $mod");
            $lastactive = $approved[0]['activeago'];
            $activeminage = $u->getSetting('modnotifs', 4);
            $backupminage = $u->getSetting('backupmodnotifs', 12);
            $minage = $u->activeModForGroup($group['id']) ? $activeminage : $backupminage;

            if ($minage < 0) {
                error_log("...off for mod $email " .  $u->getName() . " last active $lastactive");
            } else if ($lastactive === '0' || (intval($lastactive) && $lastactive <= 90)) {
                $c = new ChatMessage($dbhr, $dbhm);
                $cr = $c->getReviewCount($u, $minage > 0 ? $minage : NULL)['chatreview'];

                $now = date("Y-m-d H:i:s", time());
                $minageq = date("Y-m-d H:i:s", strtotime("$minage hours ago"));
                $earliest = date ("Y-m-d", strtotime("Midnight 31 days ago"));

                $work = [
                    'Pending Messages' => $dbhr->preQuery("SELECT COUNT(*) AS count FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid AND messages_groups.groupid = ? AND messages_groups.collection = ? AND messages_groups.deleted = 0 AND messages.heldby IS NULL AND messages.deleted IS NULL " . ($minage > 0 ? "AND messages_groups.arrival < '$minageq'" : '') . ";", [
                        $group['id'],
                        MessageCollection::PENDING
                    ])[0]['count'],
                    'Spam Messages' => $dbhr->preQuery("SELECT COUNT(*) AS count FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid AND messages_groups.groupid = ? AND messages_groups.collection = ? AND messages.arrival >= '$earliest' AND messages_groups.deleted = 0 AND messages.heldby IS NULL " . ($minage > 0 ? "AND messages_groups.arrival < '$minageq'" : '') . ";", [
                        $group['id'],
                        MessageCollection::SPAM
                    ])[0]['count'],
                    'Pending Members' => $dbhr->preQuery("SELECT COUNT(*) AS count FROM memberships WHERE groupid = ? AND collection = ? AND memberships.heldby IS NULL " . ($minage > 0 ? " AND memberships.added < '$minageq'" : '') . ";", [
                        $group['id'],
                        MembershipCollection::PENDING
                    ])[0]['count'],
                    'Pending Community Events' => $dbhr->preQuery("SELECT COUNT(DISTINCT communityevents.id) AS count FROM communityevents INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id INNER JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid WHERE communityevents_groups.groupid = ? AND communityevents.pending = 1 AND communityevents.deleted = 0 AND end >= ? " . ($minage > 0 ? "AND added < '$minageq'" : '') . ";", [
                        $group['id'],
                        $now
                    ])[0]['count'],
                    'Pending Volunteering Opportunities' => $dbhr->preQuery("SELECT COUNT(DISTINCT volunteering.id) AS count FROM volunteering LEFT JOIN volunteering_dates ON volunteering_dates.volunteeringid = volunteering.id INNER JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid WHERE volunteering_groups.groupid = ? AND volunteering.pending = 1 AND volunteering.deleted = 0 AND volunteering.expired = 0 AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) " . ($minage > 0 ? " AND added < '$minageq'" : '') . ";", [
                        $group['id'],
                        $now,
                        $now
                    ])[0]['count'],
                    'Spam Members' => $dbhr->preQuery("SELECT COUNT(*) AS count FROM users INNER JOIN memberships ON memberships.groupid = ? AND memberships.userid = users.id WHERE suspectcount > 0 " . ($minage > 0 ? " AND memberships.added < '$minageq'" : '') . ";", [
                        $group['id'],
                    ])[0]['count'],
                ];

                $total = 0;
                $nonzero = [];

                foreach ($work as $key => $val) {
                    # We want work with a non-zero count, except for pending work on Yahoo where we don't
                    # notify because Yahoo would too.
                    if ($val && (($key != 'Pending Messages' && $key != 'Pending Members')) || !$g->onYahoo()) {
                        $total += $val;
                        $nonzero[$key] = $val;
                    }
                }

                if ($total || $cr) {
                    # Some work for this mod.
                    if (!array_key_exists($mod, $mail)) {
                        $mail[$mod] = [
                            'email' => $email,
                            'name' => $name
                        ];
                    }

                    if ($cr) {
                        $mail[$mod]['Chat Messages for Review'] = $cr;
                    }

                    if ($total) {
                        $mail[$mod]['groups'][$group['nameshort']] = $nonzero;
                    }
                }

                error_log("...active mod $email " .  $u->getName() . " last active $lastactive min age $minage total $total cr $cr");
            } else {
                error_log("...idle mod  $email " .  $u->getName() . " last active $lastactive min age $minage");
            }
        }
    }
}

error_log("Send mails...");

$sent = 0;

foreach ($mail as $id => $work) {
    $textsumm = "There's work to do on ModTools:\r\n\r\n";
    $htmlsumm = '';

    $cr = presdef('Chat Messages for Review', $work, 0);
    $total = $cr;

    if ($cr) {
        $textsumm .= "You have $cr chat message" . ($cr > 1 ? 's': '') . " to review.\r\n\r\n";
        $htmlsumm .= "<p>You have <b>$cr</b> chat message" . ($cr > 1 ? 's': '') . " to review.</p>";
    }

    if (pres('groups', $work)) {
        foreach ($work['groups'] as $name => $groupwork) {
            $textsumm .= "\r\n{$name}\r\n:";
            $htmlsumm .= "<p>{$name}</p><ul>";

            foreach ($groupwork as $key => $val) {
                if ($val > 0) {
                    $textsumm .= "$key: $val\r\n";
                    $htmlsumm .= "<li>$key: <b>$val</b></li>";
                    $total += $val;
                }
            }

            $htmlsumm .= '</ul>';
        }
    }

    $textsumm .= "\r\nThese mails are fairly new.  You can control how often you get them or turn them off entirely from https://" . MOD_SITE . "/modtools/settings\r\n";

    # Now see if this is what we have already sent.
    $last = NULL;

    $ms = $dbhr->preQuery("SELECT * FROM modnotifs WHERE userid = ?;", [
        $id
    ]);

    foreach ($ms as $m) {
        $last = $m['data'];
    }

    if (!$last || strcmp($textsumm, $last) !== 0) {
        # This isn't quite right.  It means that if (say) we have 1 item of work, send a notification, mod, get
        # another item of work, then we won't send another notification until the next one comes in and it differs.
        # That's more likely to occur if you have it set to immediate.  Deleting this when we do a relevant mod op
        # wouldn't be ideal either, as we might then get notifs for the work we'd already had a chance to see.  So
        # really we ought to keep track of the latest item of work that we have notified for.
        # TODO
        $dbhm->preExec("REPLACE INTO modnotifs (userid, data) VALUES (?, ?);", [
            $id,
            $textsumm
        ]);

        $html = modnotif(MOD_SITE,  MODLOGO, $htmlsumm);
        $subj = "MODERATE: $total thing" . ($total == 1 ? '' : 's') . " to do";

        error_log("...#$id {$work['email']} $subj, chat review $cr");

        $message = Swift_Message::newInstance()
            ->setSubject($subj)
            ->setFrom([NOREPLY_ADDR => 'ModTools'])
            ->setReturnPath(NOREPLY_ADDR)
            ->setTo([ $work['email'] => $work['name'] ])
            ->setBody($textsumm);

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        list ($transport, $mailer) = getMailer();
        $mailer->send($message);

        $sent++;
    } else {
        #error_log("Skip, same");
    }
}

error_log("Sent $sent");

unlockScript($lockh);