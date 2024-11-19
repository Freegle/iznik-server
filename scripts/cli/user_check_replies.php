<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

$uid = Utils::presdef('i', $opts, NULL);

# Check if this user is suspicious, e.g. replying to many messages across a large area.
$since = date('Y-m-d', strtotime("midnight 90 days ago"));
$msg = $dbhr->preQuery("SELECT messages.id, lat, lng, groupid FROM messages 
    INNER JOIN chat_messages ON messages.id = chat_messages.refmsgid
    INNER JOIN messages_groups ON messages.id = messages_groups.msgid
    WHERE chat_messages.userid = ? AND chat_messages.date >= ? AND chat_messages.type = ?;", [
    $uid,
    $since,
    ChatMessage::TYPE_INTERESTED
]);

$s = new Spam($dbhr, $dbhm);

foreach ($msg as $m) {
    error_log("Replied to message {$m['id']} at {$m['lat']},{$m['lng']} on {$m['groupid']}");

    # Don't check memberships otherwise they might show up repeatedly.
    if ($s->checkUser($uid, NULL, $m['lat'], $m['lng'], FALSE)) {
        error_log("Review spammer");
    }
}
