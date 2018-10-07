<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');

$u = new User($dbhr, $dbhm);
$uid1 = $u->findByEmail('edward@ehibbert.org.uk');
$uid2 = $u->findByEmail('test@ehibbert.org.uk');

$c = new ChatRoom($dbhr, $dbhm);
$cid = $c->createConversation($uid1, $uid2);
$c->pokeMembers();
