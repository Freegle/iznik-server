<?php
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');

$mysqltime = date("Y-m-d H:i:s", strtotime("midnight 7 days ago"));
$messages = $dbhr->preQuery("SELECT chat_messages.*, users.fullname FROM chat_messages INNER JOIN users ON users.id = chat_messages.userid WHERE chat_messages.date >= '$mysqltime' AND reviewrequired = 1 AND reviewrejected = 0;");
$total = count($messages);
$count = 0;
$spam = 0;
$ham = 0;
$m = new ChatMessage($dbhr, $dbhm);

foreach ($messages as $message) {
    $u = new User($dbhr, $dbhm, $message['userid']);

    $snippet = substr($message['message'], 0, 80);
    if (preg_match('/(.*)$/', $snippet, $matches)) {
        $snippet = $matches[1];
    }

    $gotcha = $m->checkSpam($message['message']) || $m->checkSpam($message['fullname']);

    if ($gotcha) {
        $spam++;
        error_log("Spam: $snippet");
        $dbhm->preExec("UPDATE chat_messages SET reviewrejected = 1, reviewrequired = 0 WHERE id = ?;", [ $message['id']]);
    } else {
        error_log("Not spam: $snippet");
        $ham++;
    }
}

error_log("$count messages, $spam spam, $ham ham");