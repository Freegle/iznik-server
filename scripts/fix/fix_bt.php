<?php
# Notify by email of unread chats

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
require_once(IZNIK_BASE . '/include/user/User.php');
global $dbhr, $dbhm;

$u = new User($dbhr, $dbhm);
$uid = $u->findByEmail('mandymullaney@btinternet.com');
$uid2 = $u->findByEmail('edward@ehibbert.org.uk');
$chatid = 1335089;
$c = new ChatRoom($dbhm, $dbhm, $chatid);
$lastmsg = $c->getPublic()['lastmsg'];
error_log("Last mailed $lastmsg");

//$dbhm->errorLog = TRUE;
$cm = new ChatMessage($dbhm, $dbhm);
$mid = $cm->create($chatid, $uid2,"Test - please ignore");
error_log("Created message $mid");
$cm = new ChatMessage($dbhm, $dbhm, $mid);
error_log("Start User2Mod");
$count = $c->notifyByEmail($chatid, ChatRoom::TYPE_USER2MOD, 'investigation06@btinternet.com', -1);
error_log("Sent $count for User2Mod");
//$cm->delete();
