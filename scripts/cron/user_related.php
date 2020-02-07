<?php

# Exhort active users to do something via onsite notifications.

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$lockh = lockScript(basename(__FILE__));

# The < condition ensures we don't duplicate during a single run.
$relateds = $dbhr->preQuery("SELECT * FROM users_related WHERE notified = 0 AND user1 < user2;");

foreach ($relateds as $related) {
    $u = new User($dbhr, $dbhm, $related['user1']);
    $groups = array_column($u->getMemberships(), 'nameshort');
    $email = $u->getEmailPreferred();
    $email = $email ? $email : 'email not known';

    $str = "This is an automated mail.  We have detected users who may be the same real person.\n\n" +
        "There are several possible reasons for this:\n" +
        "1) They are confused.  You can merge accounts in ModTools from Members->Approved to help them.\n" +
        "2) Multiple people are using the same physical device, e.g. in a family.  This is probably fine.\n" +
        "3) They are trying to pretend to be multiple people.\n\n" +
        "Please review their activity on your groups; if you think it's fine, then you don't need to do anything and we won't tell you about them again.\n\n" +
        "Note that some of these emails might not be members of your group.  That would be common if they've just signed in using the wrong method.\n\n" +
        "If you need more info, please reply to this mail and Support will help you.\n\n" +
        "User #{$related['user1']} (" . $u->getName() . " $email, on " . implode(', ', $groups) . ") may be the same as:\n";
    $others = $dbhr->preQuery("SELECT * FROM users_related WHERE user1 = ?;", [
        $related['user1']
    ]);

    $anemail = FALSE;

    foreach ($others as $other) {
        $u2 = new User($dbhr, $dbhm, $other['user2']);
        $groups = array_column($u2->getMemberships(), 'nameshort');
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

    # Can only really notify if there is an email address for them to check.
    if ($anemail) {
        $dbhm->preExec("UPDATE users_related SET notified = 1 WHERE user1 = ? AND user2 = ?;", [
            $related['user1'],
            $related['user2']
        ]);

        $dbhm->preExec("UPDATE users_related SET notified = 1 WHERE user1 = ? AND user2 = ?;", [
            $related['user2'],
            $related['user1']
        ]);

        mail("log@ehibbert.org.uk", "Possible related users {$related['user1']}", $str, [], '-f' . SUPPORT_ADDR);
    }
}