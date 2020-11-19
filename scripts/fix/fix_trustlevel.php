<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

$logs = $dbhr->preQuery("SELECT * FROM logs_api where request like '%user%' AND request LIKE '%trustlevel%' ORDER BY id ASC");
foreach ($logs as $log) {
    $req = json_decode($log['request'], true);

    $trustlevel = $req['trustlevel'];
    error_log("{$req['id']} trust {$trustlevel}");

    $u = new User($dbhr, $dbhm, $req['id']);
    if ($u->getId() == $req['id']) {
        if ($trustlevel == User::TRUST_BASIC || $trustlevel == User::TRUST_DECLINED) {
            $u->setPrivate('trustlevel', $trustlevel);
        }
    }
}
