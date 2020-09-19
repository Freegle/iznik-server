<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$sql = "SELECT COUNT(*) AS count, fromuser FROM messages GROUP BY fromuser ORDER BY count DESC LIMIT 20;";
$users = $dbhr->preQuery($sql);
$tops = [];

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['fromuser']);

    if (!$u->isModerator()) {
        $msgs = $dbhr->preQuery("SELECT DISTINCT subject FROM messages WHERE fromuser = ? AND type = 'Offer';", [
            $user['fromuser']
        ]);

        $offers = [];

        foreach ($msgs as $msg) {
            $s = preg_replace('/^\[.*?\]\s*/', '', $msg['subject']);
            if (!array_key_exists($s, $offers)) {
                $offers[$s] = TRUE;
            }
        }

        foreach ($offers as $o => $p) {
            #error_log(" ..$o");
        }

        $tops[] = [ $u, $offers ];
    }
}

usort($tops, function($a, $b) {
    return(count($b[1]) - count($a[1]));
});

foreach ($tops as $top) {
    $u = $top[0];
    $o = $top[1];
    $emails = $u->getEmails();
    $onfd = FALSE;
    foreach ($emails as $email) {
        if (Mail::ourDomain($email['email'])) {
            $onfd = TRUE;
        }
    }

    $groupstr = '';
    $ctx = NULL;
    $membs = $u->getMemberships();
    foreach ($membs as $group) {
        $groupstr .= "{$group['nameshort']} ";
    }

    error_log(count($o) . " #" . $u->getId() . " " . $u->getName() . " " . $u->getEmailPreferred() . " on FD? $onfd $groupstr");
}

