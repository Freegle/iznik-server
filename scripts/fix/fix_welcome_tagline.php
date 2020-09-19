<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');

$logs = $dbhr->preQuery("SELECT * FROM logs_api where request like '%settings%' AND request LIKE '%\"call\":\"group\"%' ORDER BY id ASC");
foreach ($logs as $log) {
    $req = json_decode($log['request'], TRUE);

    $groupid = Utils::presdef('id', $req, NULL);
    $settings = Utils::presdef('settings', $req, NULL);

    if ($groupid && $settings) {
        $welcome = Utils::presdef('welcomemail', $settings, NULL);
        $tagline = Utils::presdef('tagline', $settings, NULL);

        $g = new Group($dbhr, $dbhm, $groupid);
        $settings = $g->getPrivate('settings');
        $s = json_decode($settings, TRUE);

        while (gettype($s) == 'string') {
            $s = json_decode($s, TRUE);
        }

        if ($welcome && $welcome != "1") {
            error_log("$groupid welcome");
            $s['welcomemail'] = $welcome;
            $g->setSettings($s);
        }

        if ($tagline && $tagline != "1") {
            error_log("$groupid tagline = $tagline");
        }
    }
}