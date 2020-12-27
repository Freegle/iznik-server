<?php

namespace Freegle\Iznik;

use PhpMimeMailParser\Exception;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

$id = $opts['i'];

$a = new Authority($dbhr, $dbhm, $id);

$atts = $a->getPublic();

foreach ($atts['groups'] as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);

    if ($group['overlap'] > 0.5) {
        error_log("Moderate {$group['id']} " . $g->getName() . " overlap " . $group['overlap']);
        $settings = json_decode($g->getPrivate('settings'), TRUE);

        $dbhm->preExec("UPDATE groups SET precovidmoderated = ?, groups.overridemoderation = 'None', autofunctionoverride = 1  WHERE id = ?", [
            $g->getPrivate('autofunctionoverride') ? $g->getPrivate('precovidmoderated') : $settings['moderated'],
            $group['id']
        ]);

        $settings['moderated'] = 1;
        $g->setPrivate('settings', json_encode($settings));
    }
}
