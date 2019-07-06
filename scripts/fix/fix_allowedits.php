<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$groups = $dbhr->preQuery("SELECT id FROM groups;");

foreach ($groups as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);
    $settings = json_decode($g->getPrivate('settings'), TRUE);

    if (pres('allowedits', $settings) && $settings['allowedits'] == 1) {
        error_log($g->getName());
        $settings['allowedits'] = [ 'moderated' => TRUE, 'group' => TRUE ];

        $g->setSettings($settings);
    }
}
