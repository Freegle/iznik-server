<?php
# Rescale large images in message_attachments

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$mysqltime = date("Y-m-d", strtotime("Midnight 2 days ago"));
$searches = $dbhr->preQuery("SELECT * FROM search_history WHERE date > ? ORDER BY groups, id ASC;", [ $mysqltime ]);
$last = NULL;
$deleted = 0;

foreach ($searches as $search) {
    if ($last) {
        $diff = FALSE;

        foreach (['term', 'locationid', 'groups'] as $att) {
            if ($search[$att] != $last[$att]) {
                #error_log("Differs in $att");
                $diff = TRUE;
            }
        }

        if (!$diff) {
            error_log("Delete {$search['date']} {$search['id']}");
            $dbhm->preExec("DELETE FROM search_history WHERE id = ?;", [ $search['id'] ]);
            $deleted++;
        }
    }

    $last = $search;
}

error_log("Deleted $deleted");
