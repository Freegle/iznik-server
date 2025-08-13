<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$mods = $dbhr->preQuery("SELECT DISTINCT(userid) FROM memberships WHERE role IN (?, ?);", [
    User::ROLE_MODERATOR,
    User::ROLE_OWNER
]);

$csv = [
    [
        'userid',
        'email',
        'name',
        'lastaccess',
        'activemod',
        'backupmod',
        'other known emails'
    ]
];

foreach ($mods as $mod) {
    $u = new User($dbhr, $dbhm, $mod['userid']);
    $email = $u->getEmailPreferred();
    $lastaccess = $u->getPrivate('lastaccess');
    $lastaccess = $lastaccess ? date('Y-m-d', strtotime($lastaccess)) : null;

    $modships = $u->getMemberships(TRUE);
    $active = [];
    $backup = [];

    foreach ($modships as $modship) {
        #error_log("check {$mod['userid']} on modship " . $modship['id']);
        $g = Group::get($dbhr, $dbhm, $modship['id']);
        if ($u->activeModForGroup($modship['id'])) {
            $active[] = $g->getPrivate('nameshort');
        } else {
            $backup[] = $g->getPrivate('nameshort');
        }
    }

    $otheremails = [];

    $emails = $u->getEmails();

    foreach ($emails as $anemail) {
        if (!$anemail['ourdomain'] && $anemail['email'] != $email) {
            $otheremails[] = $anemail['email'];
        }
    }

    $csv[] = [
        $mod['userid'],
        $email,
        $u->getName(),
        $lastaccess,
        implode(',', $active),
        implode(',', $backup),
        implode(',', $otheremails)
    ];
}

foreach ($csv as $row) {
    fputcsv(STDOUT, $row);
}