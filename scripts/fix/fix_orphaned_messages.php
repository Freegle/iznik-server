<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery("select messages.id, envelopeto, subject from messages left join messages_groups on messages_groups.msgid = messages.id left join chat_messages_byemail on chat_messages_byemail.msgid = messages.id where messages_groups.msgid is null and chat_messages_byemail.msgid is null LIMIT 10000;");

$groups = [];

foreach ($msgs as $msg) {
    $p = strpos($msg['envelopeto'], '@yahoogroups.com');
//    error_log("{$msg['id']} {$msg['subject']} {$msg['envelopeto']}");

    $groupname = NULL;

    if ($p != FALSE) {
        $groupname = substr($msg['envelopeto'], 0, $p);
//        error_log("...to group $groupname");
    } else {
        if (preg_match('/\[(.*?)\]/', $msg['subject'], $matches)) {
            $groupname = $matches[1];
//            error_log("...group tag $groupname");

        } else {
            error_log("{$msg['id']} {$msg['subject']} {$msg['envelopeto']}...not matched");
        }
    }

    if ($groupname) {
        $groupname = strtolower($groupname);

        if (array_key_exists($groupname, $groups)) {
            $groups[$groupname]++;
        } else {
            $groups[$groupname] = 1;
        }
    }
}

ksort($groups);
error_log("\n\nGroups:");

foreach ($groups as $groupname => $count) {
    error_log("$groupname $count");
}