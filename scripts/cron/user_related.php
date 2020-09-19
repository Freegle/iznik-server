<?php

# Exhort active users to do something via onsite notifications.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# The < condition ensures we don't duplicate during a single run.
$relateds = $dbhr->preQuery("SELECT * FROM users_related WHERE notified = 0 AND user1 < user2;");

foreach ($relateds as $related) {
    $u = new User($dbhr, $dbhm, $related['user1']);

    if (!$u->getPrivate('deleted') && !$u->isModerator()) {
        $u1membs = $u->getMemberships();
        $groups = array_column($u1membs, 'nameshort');
        $email = $u->getEmailPreferred();
        $email = $email ? $email : 'email not known';

        $str = "User #{$related['user1']} (" . $u->getName() . " $email, on " . implode(', ', $groups) . ") may be the same as:\n";
        $others = $dbhr->preQuery("SELECT * FROM users_related WHERE user1 = ?;", [
            $related['user1']
        ]);

        $anemail = FALSE;

        foreach ($others as $other) {
            $u2 = new User($dbhr, $dbhm, $other['user2']);

            if (!$u2->getPrivate('deleted') && !$u2->isModerator()) {
                $u2membs = $u2->getMemberships();
                $groups = array_column($u2membs, 'nameshort');
                $email = $u2->getEmailPreferred();

                if ($email) {
                    $anemail = TRUE;
                }

                $email = $email ? $email : 'email not known';
                $str .= "...#{$other['user2']} " . $u2->getName() . " $email, on " . implode(', ', $groups);

                if (!$other['notified']) {
                    $str .= " - just discovered";
                }

                $str .= "\n";
            }
        }

        $common = array_intersect(array_column($u1membs, 'id'), array_column($u2membs, 'id'));

        # Can only really notify if there is an email address for them to check.
        if (count($common) > 0) {
            $g = new Group($dbhr, $dbhm, $common[0]);
            mail($g->getModsEmail() . ", log@ehibbert.org.uk", "Possible related users #{$related['user1']} and #{$related['user2']} on your group",
                "This is an automated mail.  We have detected users who may be the same real person.\n\n" .
                "There are several possible reasons for this:\n" .
                "1) They are confused.  You can merge accounts in ModTools from Members->Approved to help them.\n" .
                "2) Multiple people are using the same physical device, e.g. in a family.  This is probably fine.\n" .
                "3) They are trying to pretend to be multiple people.\n\n" .
                "Please review their activity on your groups; if you think it's fine, then you don't need to do anything and we won't tell you about them again.\n\n" .
                "Note that some of these emails might not be members of your group.  That would be common if they've just signed in using the wrong method.\n\n" .
                "If you need more info, please reply to this mail and Support will help you.  You can see more background at https://discourse.ilovefreegle.org/t/identifying-related-freeglers\n\n" .
                $str,
                [], '-f' . SUPPORT_ADDR);
        } else {
            mail("log@ehibbert.org.uk", "Possible related users #{$related['user1']} and #{$related['user2']}",
                "We've mailed you about this either because there is no email for one of the users, or there are no groups in common.  That means local volunteers couldn't contact or merge them.\n\n" .
                $str,
                [], '-f' . SUPPORT_ADDR);
        }

        $dbhm->preExec("UPDATE users_related SET notified = 1 WHERE user1 = ? AND user2 = ?;", [
            $related['user1'],
            $related['user2']
        ]);

        $dbhm->preExec("UPDATE users_related SET notified = 1 WHERE user1 = ? AND user2 = ?;", [
            $related['user2'],
            $related['user1']
        ]);
    }
}