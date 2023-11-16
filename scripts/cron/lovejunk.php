<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$l = new LoveJunk($dbhr, $dbhm);

$start = date("Y-m-d", strtotime("24 hours ago"));

// Edit any messages.
$msgs = $dbhr->preQuery("SELECT DISTINCT messages.id, lovejunk.status, messages.arrival,  FROM messages 
    INNER JOIN lovejunk ON lovejunk.msgid = messages.id
    INNER JOIN messages_groups ON messages_groups.msgid = messages.id
    INNER JOIN messages_edits ON messages_edits.msgid = messages.id
    INNER JOIN `groups` ON groups.id = messages_groups.groupid
    WHERE messages.arrival >= ? AND
          messages.arrival >= '2023-07-11 16:00' AND
          messages_edits.timestamp >= lovejunk.timestamp AND
      messages.type = ? AND   
      messages_groups.collection = ? AND
      groups.onlovejunk = 1
      ORDER BY messages.arrival ASC;
", [
    $start,
    Message::TYPE_OFFER,
    MessageCollection::APPROVED
]);

foreach ($msgs as $msg) {
    $lj = json_decode($msg['status'], TRUE);

    if ($lj && array_key_exists('draftId', $lj)) {
        error_log("Edit " . $msg['id']);
        $l->edit($msg['id'], $lj['draftId']);
    }
}

// Add new messages.
$msgs = $dbhr->preQuery("SELECT messages.id FROM messages 
    LEFT JOIN lovejunk ON lovejunk.msgid = messages.id
    INNER JOIN messages_groups ON messages_groups.msgid = messages.id 
    INNER JOIN `groups` ON groups.id = messages_groups.groupid
    WHERE messages.arrival >= ? AND
          messages.arrival >= '2023-06-13 14:50' AND
      messages.type = ? AND   
      lovejunk.msgid IS NULL AND
      messages_groups.collection = ? AND
      groups.onlovejunk = 1
      ORDER BY messages.arrival ASC;
", [
    $start,
    Message::TYPE_OFFER,
    MessageCollection::APPROVED
]);

foreach ($msgs as $msg) {
    error_log("Send " . $msg['id']);
    $l->send($msg['id']);
}

// Mark any messages which we have sent to LoveJunk and which now have outcomes as deleted or (if promised) completed.
$msgs = $dbhr->preQuery("SELECT messages.id FROM messages_outcomes
    INNER JOIN messages ON messages.id = messages_outcomes.msgid
    INNER JOIN messages_groups ON messages_groups.msgid = messages.id
    INNER JOIN lovejunk ON lovejunk.msgid = messages_outcomes.msgid
    INNER JOIN `groups` ON groups.id = messages_groups.groupid
    WHERE messages_outcomes.timestamp >= ? AND
      messages.type = ? AND   
      lovejunk.success = 1 AND lovejunk.deleted IS NULL AND lovejunk.status LIKE '{%' AND 
      groups.onlovejunk = 1
      ORDER BY messages.arrival ASC;
", [
    $start,
    Message::TYPE_OFFER,
]);

foreach ($msgs as $msg) {
    $l->completeOrDelete($msg['id']);
}

Utils::unlockScript($lockh);