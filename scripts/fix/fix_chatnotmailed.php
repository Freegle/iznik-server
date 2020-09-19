<?php
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');

$mysqltime = date("Y-m-d H:i:s", strtotime("midnight 3 days ago"));
$mysqltime2 = date("Y-m-d H:i:s", strtotime("20 minutes ago"));
$sql = "SELECT * FROM chat_messages WHERE date >= '$mysqltime' AND date <= '$mysqltime2' AND mailedtoall = 0 AND seenbyall = 0 AND reviewrequired = 0 AND reviewrejected = 0 AND chat_messages.type = 'Default' ORDER BY date ASC;";
$msgs = $dbhr->preQuery($sql);

foreach ($msgs as $msg) {
    $maxs = $dbhr->preQuery("SELECT MAX(id) AS max FROM chat_messages WHERE chatid = ?;", [
        $msg['chatid']
    ]);

    foreach ($maxs as $max) {
        if ($max['max'] == $msg['id']) {
            # Last message in chat.
            $chats = $dbhr->preQuery("SELECT * FROM chat_rooms WHERE id = ? AND chattype = ? AND user1 != user2;", [
                $msg['chatid'],
                ChatRoom::TYPE_USER2USER
            ]);

            foreach ($chats as $chat) {
                $otheru = $chat['user1'] == $msg['userid'] ? $chat['user2'] : $chat['user1'];

                $u = new User($dbhr, $dbhm, $otheru);

                # Should we have mailed it?
                if ($u->notifsOn(User::NOTIFS_EMAIL) && !$u->getPrivate('bouncing') && !$u->getPrivate('deleted') && $u->getEmailPreferred()) {
                    $last = $dbhr->preQuery("SELECT * FROM chat_roster WHERE chatid = ? AND userid = ?;", [
                        $msg['chatid'],
                        $otheru
                    ]);

                    # Has the recipient blocked us?
                    $blocked = $dbhr->preQuery("SELECT * FROM chat_roster WHERE chatid = ? AND userid = ? AND status = 'Blocked';", [
                        $msg['chatid'],
                        $otheru
                    ]);

                    if (!count($blocked) && (!count($last) || $last[0]['lastmsgemailed'] < $msg['id'])) {
                        error_log("...should have emailed  {$msg['id']} in {$chat['id']} to $otheru");
                        error_log(var_export($blocked, TRUE));
                    }
                }
            }
        }
    }
}