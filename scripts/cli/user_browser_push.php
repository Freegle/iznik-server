<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:');

$email = Utils::presdef('e', $opts, NULL);

$u = new User($dbhr, $dbhm);
$uid = $u->findByEmail($email);

$l = new PushNotifications($dbhr, $dbhm);
$l->notify($uid, FALSE, TRUE);
error_log("Notified");