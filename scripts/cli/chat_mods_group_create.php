<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('g:');
$gid = count($opts) > 0 ? $opts['g'] : NULL;

$sql = "SELECT id FROM groups " . ($gid ? " WHERE id = $gid" : "") . " ORDER BY nameshort ASC;";
$groups = $dbhr->query($sql);

$r = new ChatRoom($dbhr, $dbhm);

foreach ($groups as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);
    echo("Group #{$group['id']} " . $g->getPrivate('nameshort') . "\n");
    $chats = $dbhr->preQuery("SELECT * FROM chat_rooms WHERE groupid = ? AND chattype = 'Mod2Mod';", [ $group['id'] ]);

    if (count($chats) == 0) {
        $r->createGroupChat($g->getPrivate('nameshort') . ' Volunteers', $group['id'], TRUE, TRUE);
        $r->setPrivate('description', $g->getPrivate('nameshort') . ' Volunteers');
    }
}
