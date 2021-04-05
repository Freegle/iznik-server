<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$ids = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE contenttype != '' OR identification IS NOT NULL;");
$total = count($ids);
$count = 0;

foreach ($ids as $id) {
    $dbhm->preExec("UPDATE messages_attachments SET contenttype = '', identification = NULL WHERE id = ?;", [
        $id['id']
    ]);

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}