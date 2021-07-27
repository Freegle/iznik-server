<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/user/User.php');
global $dbhr, $dbhm;

$u = User::get($dbhr, $dbhm);

$messages = $dbhr->preQuery("SELECT messages.id, messages.fromuser, messages.fromaddr FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid WHERE fromaddr LIKE 'notify-%' UNION SELECT messages.id, messages.fromuser, messages.fromaddr FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid WHERE fromaddr LIKE 'replyto-%';");

$count = 0;

foreach ($messages as $message) {
    $m = new Message($dbhr, $dbhm, $message['id']);

    if ($message['fromuser']) {
        $u = new User($dbhr, $dbhm, $message['fromuser']);
        $email = $u->getOurEmail();

        if (!$email) {
            $email = $u->inventEmail();
        }

        error_log("#{$message['id']} {$message['fromaddr']} => $email");
        $m->setPrivate('fromaddr', $email);
    }
}
