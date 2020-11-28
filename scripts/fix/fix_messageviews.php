<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$views = $dbhr->preQuery("SELECT messages_likes.msgid, messages_likes.userid FROM messages_likes inner join microactions on microactions.msgid = messages_likes.msgid and messages_likes.userid = microactions.userid");

foreach ($views as $view) {
    $dbhm->preExec("DELETE FROM messages_likes WHERE msgid = ? AND userid = ?;", [
        $view['msgid'],
        $view['userid']
    ]);
}