<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$phones = $dbhr->preQuery("SELECT * FROM users_phones");
$u = new User($dbhr, $dbhm);

foreach ($phones as $phone) {
    $old = $phone['number'];
    $new = $u->formatPhone($old);

    if ($new != $old) {
        error_log("$old => $new");
        $dbhm->preExec("UPDATE users_phones SET number = ? WHERE id = ?;", [
            $new,
            $phone['id']
        ]);
    }
}