<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

error_log("Start at " . date("Y-m-d H:i:s"));

$u = User::get($dbhr, $dbhm);
$uid = $u->findByEmail('sheilasmail.cp@gmail.com');

if ($uid) {
    $membs = $dbhr->preQuery("SELECT * FROM memberships WHERE userid = $uid AND role IN ('Owner', 'Moderator') ORDER BY id;");
    $active = 0;
    $track = [];

    foreach ($membs as $memb) {
        if (pres('settings', $memb)) {
            $settings = json_decode($memb['settings'], TRUE);
            error_log("{$memb['groupid']} active {$settings['active']}");

            if ($settings['active']) {
                $active++;
                $track[] = $memb['groupid'];
            }
        }
    }

    error_log("Active mod for $active");
    $last = @file_get_contents('/tmp/sheila');
    $last = $last ? json_decode($last, TRUE) : [
        'active' => 0,
        'groups' => []
    ];

    if ($last['active'] != $active) {
        error_log("Differs");
        mail('edward@ehibbert.org.uk, sheilasmail.cp@gmail.com', "Sheila active mod count changed from {$last['active']} to $active", "Last active mod for " . json_encode($last['groups']) . "\r\n\r\nCurrently active mod for " . json_encode($track) . "\r\n\r\nDifference " . json_encode(array_diff($last['groups'], $track)));
    }

    file_put_contents('/tmp/sheila', json_encode([
        'active' => $active,
        'groups' => $track
    ]));
}