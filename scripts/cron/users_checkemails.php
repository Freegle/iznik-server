<?php
# Notify by email of unread chats

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

$validator = new Swift_Validate();

$start = date('Y-m-d', strtotime("25000 hours ago"));
$count = 0;
$list = '';

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }

    return (substr($haystack, -$length) === $needle);
}

function fixMail($email) {
    $email = trim($email);

    if (endsWith($email, '.') || endsWith($email, ',')) {
        $email = substr($email, 0, -1);
    }

    return($email);
}

$emails = $dbhr->preQuery("SELECT email, userid FROM users_emails WHERE added > '$start' ORDER BY added DESC;", NULL, FALSE, FALSE);
foreach ($emails as $email) {
    $fixed = fixMail($email['email']);

    if (!$validator->email($email['email']) && $validator->email($fixed)) {
        $u = new User($dbhr, $dbhm);
        $uid = $u->findByEmail($fixed);

        $seeds = $dbhr->preQuery("SELECT * FROM returnpath_seedlist WHERE email LIKE ?", [
            $fixed
        ]);

        if (count($seeds) > 0) {
            error_log("Found seed $fixed");
            $u = new User($dbhr, $dbhm, $email['userid']);
            $u->forget('Removing bad email');
        }

        if (!$uid) {
            error_log("Fix {$email['email']} to $fixed");
            $u = new User($dbhr, $dbhm, $email['userid']);
            $u->removeEmail($email['email']);
            $u->addEmail($fixed);
        } else {
            # Already exists, probably needs merge or delete.
            $count++;
            $list .= "\"{$email['email']}\" - $fixed exists, so needs either merge or delete\n";
        }
    } else if (!$validator->email($email['email'])) {
        error_log($email['email'] . " is not valid");
        $count++;
        $list .= "\"{$email['email']}\"\n";
    }
}

unlockScript($lockh);

error_log("\n\nFound $count invalid");

if ($count > 0) {
    mail("log@ehibbert.org.uk", "$count Invalid Emails - please check", "These are emails which have got into the system which aren't valid.  Usually this is a typo; sometimes the member will have registered again with a corrected email; sometimes they won't and they'll just not be getting mails at all.  Please investigate these and either fix them to something sensible (by impersonating) or purge the user.\r\n\r\nThe mails are in quotes so you can see any spacing.\r\n\r\n$list", [], '-f' . NOREPLY_ADDR);
}