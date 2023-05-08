<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$chats = $dbhr->preQuery("select * from chat_rooms where chattype = 'User2Mod' and groupid is null;");

foreach ($chats as $chat) {
    error_log("Fixing chat " . $chat['id'] . " for user " . $chat['user1']);
    $fixed = FALSE;

    $mods = $dbhr->preQuery("SELECT userid FROM chat_messages WHERE chatid = ? AND userid != ?;", [
        $chat['id'],
        $chat['user1']
    ]);

    foreach ($mods as $mod) {
        
    }
    
    if (count($mods) > 0) {
        error_log("...mod is " . $mod['userid']);;
        $overlap = $dbhr->preQuery("SELECT m1.groupid FROM memberships m1 INNER JOIN memberships m2 ON m1.groupid = m2.groupid WHERE m1.userid = ? AND m2.userid = ?;", [
            $chat['user1'],
            $mod['userid']
        ]);

        if (count($overlap) > 0) {
            error_log("...both on group " . $overlap[0]['groupid']);
            $dbhm->preExec("UPDATE chat_rooms SET groupid = ? WHERE id = ?;", [
                $overlap[0]['groupid'],
                $chat['id']
            ]);
            $fixed = TRUE;
        }
    }
    
    if (!$fixed) {
        # No group in common with a mod.  Just pick the last group the user was active on.
        $groups = $dbhr->preQuery("select distinct(groupid) from logs where user = ? AND groupid IS NOT NULL ORDER BY timestamp DESC LIMIT 1;", [
            $chat['user1']
        ]);

        if (count($groups) > 0) {
            error_log("...last on {$groups[0]['groupid']}");
            $dbhm->preExec("UPDATE chat_rooms SET groupid = ? WHERE id = ?;", [
                $groups[0]['groupid'],
                $chat['id']
            ]);
            $fixed = TRUE;
        }

    }

    if (!$fixed) {
        error_log("ERROR: {$chat['id']} Can't find group in common.");
    }
}