<?php
# Rescale large images in message_attachments

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Alerts.php');


$missings = $dbhr->preQuery("SELECT id, nameshort FROM groups WHERE id NOT IN (SELECT groupid FROM alerts_tracking WHERE alertid = 8829) AND type = 'Freegle' AND publish = 1 AND onmap = 1 ORDER BY nameshort ASC;");
error_log(count($missings) . " missing");
$a = new Alert($dbhr, $dbhm, 8829);

foreach ($missings as $group) {
    error_log("...{$group['nameshort']}");
    $a->mailMods(8829, $group['id'], 1, FALSE);
}
