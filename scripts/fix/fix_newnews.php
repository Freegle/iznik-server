<?php

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/newsfeed/Newsfeed.php');

$lockh = Utils::lockScript(basename(__FILE__));

$n = new Newsfeed($dbhr, $dbhm);
$groups = $dbhr->preQuery("SELECT * FROM `groups` WHERE type = 'Freegle' AND onhere = 1 AND publish = 1 AND nameshort LIKE '%edinburgh%' ORDER BY RAND();");
foreach ($groups as $group) {
    $g = new Group($dbhr, $dbhm, $group['id']);

    if ($g->getSetting('newsfeed', TRUE)) {
        error_log("{$group['nameshort']}");

        $membs = $dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid = ? AND collection = ? AND userid = 35909200;", [
            $group['id'],
            MembershipCollection::APPROVED
        ]);

        $count = 0;
        foreach ($membs as $memb) {
            try {
                error_log("Send to {$memb['userid']}");
                $count += $n->digest($memb['userid'], FALSE);
            } catch (\Exception $e) {}
        }

        if ($count) {
            error_log("{$group['nameshort']} sent $count");
        }
    } else {
        error_log("{$group['nameshort']} skipped");
    }
}

Utils::unlockScript($lockh);