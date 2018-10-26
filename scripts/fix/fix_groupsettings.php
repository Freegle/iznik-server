<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$groups = $dbhr->preQuery("SELECT id FROM groups;");

foreach ($groups as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);
    $settings = json_decode($g->getPrivate('settings'), TRUE);
    unset($settings['description']);
    unset($settings['branding']);
    unset($settings['welcomemail']);
    unset($settings['offerkeyword']);
    unset($settings['wantedkeyword']);
    unset($settings['takenkeyword']);
    unset($settings['receivedkeyword']);
    unset($settings['keywords']['OFFER']);
    unset($settings['keywords']['WANTED']);
    unset($settings['keywords']['TAKEN']);
    unset($settings['keywords']['RECEIVED']);
    unset($settings['keywords']['offerkeyword']);
    unset($settings['keywords']['wantedkeyword']);
    unset($settings['keywords']['takenkeyword']);
    unset($settings['keywords']['receivedkeyword']);
    unset($settings['chaseups']['idle']);

    $g->setSettings($settings);
}
