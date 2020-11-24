<?php
namespace Freegle\Iznik;

define('IMAGE_DOMAIN', 'images.ilovefreegle.org');

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:e:');

$c = new ChatRoom($dbhr, $dbhm, $opts['i']);
$count = $c->notifyByEmail($opts['i'], $c->getPrivate('chattype'), $opts['e'], 0, FALSE, "10 years ago", TRUE);
error_log("Sent $count to User2User " . date("Y-m-d H:i:s", time()));
