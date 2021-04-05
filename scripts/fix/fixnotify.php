<?php
//const MODTOOLS = TRUE;  // MT
const MODTOOLS = FALSE;   // FD

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/user/PushNotifications.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

use Freegle\Iznik\PushNotifications as PushNotifications;

$l = new PushNotifications($dbhr, $dbhm);
#$g = Group::get($dbhr, $dbhm);

#$gid = $g->findByShortName('FreeglePlayground');
#$l->notifyGroupMods($gid);
#$l->notify(35909200, TRUE);
#$l->notify(13437455, TRUE);
#$l->notify(32496365, TRUE); // MT chriscant
$l->notify(32496365, FALSE); // FD
