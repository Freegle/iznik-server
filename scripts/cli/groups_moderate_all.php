<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$groups = $dbhr->preQuery("SELECT id FROM groups WHERE type = 'Freegle';");

foreach ($groups as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);
    $settings = json_decode($g->getPrivate('settings'), TRUE);
    $settings['moderated'] = 1;
    $g->setPrivate('settings', json_encode($settings));
    echo "redis-cli DEL group-{$group['id']}\n";
}
