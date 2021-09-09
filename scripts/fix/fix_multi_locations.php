<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery("SELECT messages.id, messages.fromuser, subject from messages inner join messages_groups on messages_groups.msgid = messages.id where messages.arrival >= '2021-08-01' and fromaddr not like '%trashnothing%'");
$users = [];

foreach ($msgs as $msg) {
    if (preg_match('/.*?\:(.*)\((.*)\)/', $msg['subject'], $matches)) {
        $loc = trim($matches[2]);

        if (!array_key_exists($msg['fromuser'], $users)) {
            $users[$msg['fromuser']] = [];
        }

        if (!in_array($loc, $users[$msg['fromuser']])) {
            $users[$msg['fromuser']][] = $loc;
        }
    }
}

error_log("Number of users " . count($users));

$multiple = 0;

foreach ($users as $key => $user) {
    if (count($user) > 1) {
        $multiple++;
    }
}

error_log("...with multiple $multiple");
