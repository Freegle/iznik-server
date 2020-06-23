<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/message/MessageCollection.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');

$l = new Log($dbhr, $dbhm);

# Look for messages which have been pending for too long.  This fallback catches cases where the group is not being
# regularly moderated.
$sql = "SELECT msgid, groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS ago FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid WHERE collection = ? AND heldby IS NULL HAVING ago > 48;";
$messages = $dbhr->preQuery($sql, [
    MessageCollection::PENDING
]);

foreach ($messages as $message) {
    $m = new Message($dbhr, $dbhm, $message['msgid']);
    $uid = $m->getFromuser();
    $u = new User($dbhr, $dbhm, $uid);

    $gids = $m->getGroups();
    $oldenough = FALSE;

    foreach ($gids as $gid) {
        $joined = $u->getMembershipAtt($gid, 'added');
        $hoursago = round((time() - strtotime($joined)) / 3600);

        error_log("{$message['msgid']} has been pending for {$message['ago']}, membership $hoursago");

        if ($hoursago > 48) {
            error_log("...approve");
            $m->approve($message['groupid']);

            $l->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_AUTO_APPROVED,
                'groupid' => $message['groupid'],
                'msgid' => $message['msgid'],
                'user' => $m->getFromuser()
            ]);
        }
    }
}
