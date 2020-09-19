<?php
# Notify by email of unread chats
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$c = new ChatRoom($dbhr, $dbhm);

$chats = $c->chaseupMods();
$count = count($chats);
error_log("Chased up $count groups for User2Mod");

Utils::unlockScript($lockh);