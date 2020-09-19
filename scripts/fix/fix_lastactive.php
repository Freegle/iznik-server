<?php
# Script to bump lastaccess for members of a group.  Used after importing a group from Yahoo to trigger sending
# mails to old users.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');


$opts = getopt('i:');

$gid = $opts['i'];

$membs = $dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = $gid");

foreach ($membs as $memb) {
    error_log($memb['userid']);
    $dbhm->preExec("UPDATE users SET lastaccess = NOW() WHERE id = {$memb['userid']}");
}