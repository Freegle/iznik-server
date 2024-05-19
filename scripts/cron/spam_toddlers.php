<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Find active users who are muted on ChitChat.
$news = $dbhr->preQuery("SELECT DISTINCT userid FROM logs_api INNER JOIN users ON logs_api.userid = users.id WHERE logs_api.userid IS NOT NULL AND users.newsfeedmodstatus = ?", [
    User::NEWSFEED_SUPPRESSED
]);

foreach ($news as &$user) {
    $user['newsfeed'] = TRUE;
}


# Find active users who are on the spammer list.
$spammers = $dbhr->preQuery("SELECT DISTINCT spam_users.userid FROM logs_api INNER JOIN spam_users ON logs_api.userid = spam_users.userid WHERE collection = ?", [
    Spam::TYPE_SPAMMER
]);

foreach ($spammers as &$spammer) {
    $spammer['spam'] = TRUE;
}


if (file_exists('/var/www/toddlers.json')) {
    $found = json_decode(file_get_contents('/var/www/toddlers.json'), TRUE);
} else {
    $found = [];
}

error_log("Start at " . date("Y-m-d H:i:s") . " with " .
          count($news) . " active users muted on ChitChat, " .
          count($spammers) . " active users on spam list, and " .
          count($found) . " previously processed.");

foreach (array_merge($spammers, $news) as $user) {
    # Find the IP addresses they're using.
    $ips = $dbhr->preQuery("SELECT DISTINCT ip FROM logs_api WHERE userid = ?", [$user['userid']]);

    foreach ($ips as $ip) {
        # Find users using the same IP recently.
        $others = $dbhr->preQuery("SELECT DISTINCT l2.userid FROM logs_api l1
                   INNER JOIN logs_api l2 ON l1.ip = l2.ip
                   WHERE l1.ip = ? AND l2.userid IS NOT NULL AND l2.userid != l1.userid AND
                   ((l1.date >= l2.date AND l1.date < l2.date + INTERVAL 30 MINUTE) OR
                    (l2.date >= l1.date AND l1.date < l2.date - INTERVAL 30 MINUTE))
                    ", [
            $ip['ip']
        ]);

        foreach ($others as $other) {
            if (!array_key_exists($other['userid'], $found)) {
                $found[$other['userid']] = 1;
                $u = User::get($dbhr, $dbhm, $other['userid']);

                if ($u->getPrivate('systemrole') === User::SYSTEMROLE_USER) {
                    # Check that this IP hasn't been used by a mod. If it was, they're probably impersonating.
                    $mods = $dbhr->preQuery("SELECT DISTINCT userid FROM logs_api 
                   INNER JOIN users ON users.id = logs_api.userid 
                   WHERE ip = ? AND systemrole != ? AND logs_api.userid IS NOT NULL", [
                        $ip['ip'],
                        User::SYSTEMROLE_USER
                    ]);

                    if (!count($mods)) {
                        $str = $other['userid'] . " used {$ip['ip']} within 30 minutes of " . $user['userid'];

                        if (Utils::pres('spam', $user)) {
                            $str .= " who is on the spammer list.  This is not a guarantee that they are a spammer, but it's a good indicator.  Please check.";
                            $s = new Spam($dbhr, $dbhm);
                            $s->addSpammer($other['userid'], Spam::TYPE_PENDING_ADD, $str);
                        } else {
                            $str .= " who is muted on ChitChat (mute them too)";

                            if ($u->getPrivate('newsfeedmodstatus') != User::NEWSFEED_SUPPRESSED) {
                                $u->setPrivate('newsfeedmodstatus', User::NEWSFEED_SUPPRESSED);
                                error_log($str);
                            }
                        }
                    }
                }
            }
        }
    }
}

file_put_contents('/var/www/toddlers.json', json_encode($found));

Utils::unlockScript($lockh);