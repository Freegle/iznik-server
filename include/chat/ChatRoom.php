<?php
namespace Freegle\Iznik;

use Pheanstalk\Pheanstalk;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

require_once(IZNIK_BASE . '/mailtemplates/chat_chaseup_mod.php');

class ChatRoom extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'name', 'chattype', 'groupid', 'description', 'user1', 'user2', 'synctofacebook');
    var $settableatts = array('name', 'description');

    const ACTIVELIM = "31 days ago";
    const ACTIVELIM_MT = "365 days ago";
    const REPLY_GRACE = 30;
    const EXPECTED_GRACE = 24 * 60;
    const EXPECTED_CHASEUP = 5;
    const TYPE_MOD2MOD = 'Mod2Mod';
    const TYPE_USER2MOD = 'User2Mod';
    const TYPE_USER2USER = 'User2User';
    const TYPE_GROUP = 'Group';

    const STATUS_ONLINE = 'Online';
    const STATUS_OFFLINE = 'Offline';
    const STATUS_AWAY = 'Away';
    const STATUS_CLOSED = 'Closed';
    const STATUS_BLOCKED = 'Blocked';

    const ACTION_ALLSEEN = 'AllSeen';
    const ACTION_NUDGE = 'Nudge';
    const ACTION_TYPING = 'Typing';
    const ACTION_REFER_TO_SUPPORT = 'ReferToSupport';

    const DELAY = 30;
    const CACHED_LIST_SIZE = 20;

    /** @var  $log Log */
    private $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        # Always use the master, because cached results mess up presence.  We don't use fetch() because
        # we want to get all the info we will need for the summary in a single DB op.  This helps significantly
        # for users with many chats.
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->name = 'chatroom';
        $this->chatroom = NULL;
        $this->table = 'chat_rooms';

        $myid = Session::whoAmId($this->dbhr, $this->dbhm);

        $this->ourFetch($id, $myid);

        $this->log = new Log($dbhr, $dbhm);
    }

    private function ourFetch($id, $myid) {
        if ($id) {
            $rooms = $this->fetchRooms([ $id ], $myid, FALSE);

            if (count($rooms)) {
                $this->id = $id;
                $this->chatroom = $rooms[0];
            }
        }
    }

    public function fetchRooms($ids, $myid, $public) {
        # This is a slightly complicated query which:
        # - gets the chatroom object
        # - gets the group name from the groups table, which we use in naming the chat
        # - gets user names for the users (if present), which we also use in naming the chat
        # - gets the most recent chat message (if any) which we need for getPublic()
        # - gets the count of unread messages for the logged in user.
        # - gets the count of reply requested from other people
        # - gets the refmsgids for chats with unread messages
        # - gets any profiles for the users
        # - gets any most recent chat message info
        # - gets the last seen for this user.
        #
        # We do this because chat rooms are performance critical, especially for people with many chats.
        $oldest = date("Y-m-d", strtotime("Midnight 31 days ago"));
        $idlist = "(" . implode(',', $ids) . ")";
        $modq = Session::modtools() ? "" : " AND reviewrequired = 0 AND reviewrejected = 0 AND (processingsuccessful = 1 OR processingrequired = 0) ";
        $sql = "
SELECT chat_rooms.*, CASE WHEN namefull IS NOT NULL THEN namefull ELSE nameshort END AS groupname, 
CASE WHEN u1.fullname IS NOT NULL THEN u1.fullname ELSE CONCAT(u1.firstname, ' ', u1.lastname) END AS u1name,
CASE WHEN u2.fullname IS NOT NULL THEN u2.fullname ELSE CONCAT(u2.firstname, ' ', u2.lastname) END AS u2name,
u1.settings AS u1settings,
u2.settings AS u2settings,
(SELECT COUNT(*) AS count FROM chat_messages WHERE id > 
  COALESCE((SELECT lastmsgseen FROM chat_roster WHERE chatid = chat_rooms.id AND userid = ? AND status != ? AND status != ?), 0) 
  AND chatid = chat_rooms.id AND userid != ? $modq) AS unseen,
(SELECT COUNT(*) AS count FROM chat_messages WHERE chatid = chat_rooms.id AND replyexpected = 1 AND replyreceived = 0 AND userid != ? AND chat_messages.date >= '$oldest' AND chat_rooms.chattype = 'User2User') AS replyexpected,
i1.id AS u1imageid,
i2.id AS u2imageid,
i3.id AS gimageid,
chat_messages.id AS lastmsg, chat_messages.message AS chatmsg, chat_messages.date AS lastdate, chat_messages.type AS chatmsgtype" .
            ($myid ?
", CASE WHEN chat_rooms.chattype = 'User2Mod' AND chat_rooms.user1 != $myid THEN 
  (SELECT MAX(chat_roster.lastmsgseen) AS lastmsgseen FROM chat_roster WHERE chatid = chat_rooms.id AND userid = $myid)
ELSE
  (SELECT chat_roster.lastmsgseen FROM chat_roster WHERE chatid = chat_rooms.id AND userid = $myid)
END AS lastmsgseen" : '') . ",     
messages.type AS refmsgtype
FROM chat_rooms LEFT JOIN `groups` ON groups.id = chat_rooms.groupid 
LEFT JOIN users u1 ON chat_rooms.user1 = u1.id
LEFT JOIN users u2 ON chat_rooms.user2 = u2.id 
LEFT JOIN users_images i1 ON i1.userid = u1.id
LEFT JOIN users_images i2 ON i2.userid = u2.id
LEFT JOIN groups_images i3 ON i3.groupid = chat_rooms.groupid 
LEFT JOIN chat_messages ON chat_messages.id = (SELECT id FROM chat_messages WHERE chat_messages.chatid = chat_rooms.id $modq ORDER BY chat_messages.id DESC LIMIT 1)
LEFT JOIN messages ON messages.id = chat_messages.refmsgid
WHERE chat_rooms.id IN $idlist;";

        $rooms = $this->dbhm->preQuery($sql, [
            $myid,
            ChatRoom::STATUS_CLOSED,
            ChatRoom::STATUS_BLOCKED,
            $myid,
            $myid
        ],FALSE,FALSE);

        # We might have duplicate rows due to image ids.  Newest wins.
        $newroom = [];

        foreach ($rooms as $room) {
            $newroom[$room['id']] = $room;
        }

        $rooms = array_values($newroom);

        $ret = [];
        $refmsgids = [];
        $otheruids = [];

        foreach ($rooms as &$room) {
            $room['u1name'] = Utils::presdef('u1name', $room, 'Someone');
            $room['u2name'] = Utils::presdef('u2name', $room, 'Someone');

            if (Utils::pres('lastdate', $room)) {
                $room['lastdate'] = Utils::ISODate($room['lastdate']);
                $room['latestmessage'] = Utils::ISODate($room['latestmessage']);
            }

            if (!Session::modtools()) {
                # We might be forbidden from showing the profiles.
                $u1settings = Utils::pres('u1settings', $room) ? json_decode($room['u1settings'], TRUE) : NULL;
                $u2settings = Utils::pres('u2settings', $room) ? json_decode($room['u2settings'], TRUE) : NULL;

                if (!is_null($u1settings) && !Utils::pres('useprofile', $u1settings)) {
                    $room['u1defaultimage'] = TRUE;
                }

                if (!is_null($u2settings) && !Utils::pres('useprofile', $u2settings)) {
                    $room['u2defaultimage'] = TRUE;
                }
            }

            switch ($room['chattype']) {
                case ChatRoom::TYPE_USER2USER:
                    # We use the name of the user who isn't us, because that's who we're chatting to.
                    $room['name'] = $myid == $room['user1'] ? $room['u2name'] : $room['u1name'];
                    $otheruids[] = $myid == $room['user1'] ? $room['user2'] : $room['user1'];
                    break;
                case ChatRoom::TYPE_USER2MOD:
                    # If we started it, we're chatting to the group volunteers; otherwise to the user.
                    $room['name'] = ($room['user1'] == $myid) ? ($room['groupname'] . " Volunteers") : ($room['u1name'] . " on " . $room['groupname']);
                    break;
                case ChatRoom::TYPE_MOD2MOD:
                    # Mods chatting to each other.
                    $room['name'] = $room['groupname'] . " Mods";
                    break;
                case ChatRoom::TYPE_GROUP:
                    # Members chatting to each other
                    $room['name'] = $room['groupname'] . " Discussion";
                    break;
            }

            if ($public) {
                # We want the public version of the attributes.  This is similar code to getPublic(); perhaps
                # could be combined.
                $thisone = [
                    'chattype' => $room['chattype'],
                    'description' => $room['description'],
                    'groupid' => $room['groupid'],
                    'lastdate' => Utils::presdef('lastdate', $room, NULL),
                    'lastmsg' => Utils::presdef('lastmsg', $room, NULL),
                    'synctofacebook' => $room['synctofacebook'],
                    'unseen' => $room['unseen'],
                    'name' => $room['name'],
                    'id' => $room['id'],
                    'replyexpected' => $room['replyexpected'],
                    'user1id' => $room['user1'],
                    'user2id' => $room['user2']
                ];
                
                $thisone['snippet'] = $this->getSnippet($room['chatmsgtype'], $room['chatmsg'], $room['refmsgtype']);

                switch ($room['chattype']) {
                    case ChatRoom::TYPE_USER2USER:
                        if ($room['user1'] == $myid) {
                            $thisone['icon'] = Utils::pres('u2defaultimage', $room) ? ('https://' . USER_DOMAIN . '/defaultprofile.png') : ('https://' . IMAGE_DOMAIN . "/tuimg_" . $room['u2imageid']  . ".jpg");
                        } else {
                            $thisone['icon'] = Utils::pres('u1defaultimage', $room) ? ('https://' . USER_DOMAIN . '/defaultprofile.png') : ('https://' . IMAGE_DOMAIN . "/tuimg_" . $room['u1imageid'] . ".jpg");
                        }
                        break;
                    case ChatRoom::TYPE_USER2MOD:
                        if ($room['user1'] == $myid) {
                            $thisone['icon'] =  "https://" . IMAGE_DOMAIN . "/gimg_{$room['gimageid']}.jpg";
                        } else{
                            $thisone['icon'] = 'https://' . IMAGE_DOMAIN . "/tuimg_" . $room['u1imageid'] . ".jpg";
                        }
                        break;
                    case ChatRoom::TYPE_MOD2MOD:
                        $thisone['icon'] = "https://" . IMAGE_DOMAIN . "/gimg_{$room['gimageid']}.jpg";
                        break;
                    case ChatRoom::TYPE_GROUP:
                        $thisone['icon'] = "https://" . IMAGE_DOMAIN . "/gimg_{$room['gimageid']}.jpg";
                        break;
                }

                if ($room['unseen']) {
                    # We want to return the refmsgids for this chat.
                    $refmsgids[] = $room['id'];
                }

                $ret[] = $thisone;
            } else {
                # We are fetching internally
                $ret[] = $room;
            }
        }

        if (count($refmsgids)) {
            $sql = "SELECT DISTINCT refmsgid, chatid FROM chat_messages WHERE chatid IN (" . implode(',', $refmsgids) . ") AND refmsgid IS NOT NULL;";
            $ids = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);

            foreach ($ids as $id) {
                foreach ($ret as &$chat) {
                    if ($chat['id'] ==  $id['chatid']) {
                        if (Utils::pres('refmsgids', $chat)) {
                            $chat['refmsgids'][] = $id['refmsgid'];
                        } else {
                            $chat['refmsgids'] = [ $id['refmsgid'] ];
                        }
                    }
                }
            }
        }

        if (count($otheruids)) {
            $u = new User($this->dbhr, $this->dbhm);
            $users = [];

            for ($i = 0; $i < count($ret); $i++) {
                if (Utils::pres('user1id', $ret[$i])) {
                    $users[$ret[$i]['user1id']]['supporter'] = false;
                }

                if (Utils::pres('user2id', $ret[$i])) {
                    $users[$ret[$i]['user2id']]['supporter'] = false;
                }
            }

            $u->getSupporters($users, $users);

            for ($i = 0; $i < count($ret); $i++) {
                if ($ret[$i]['chattype'] ==  ChatRoom::TYPE_USER2USER && Utils::pres('user1id', $ret[$i]) && Utils::pres('user2id', $ret[$i])) {
                    if ($ret[$i]['user1id'] ==  $myid) {
                        $ret[$i]['supporter'] = $users[$ret[$i]['user2id']]['supporter'];
                    } else {
                        $ret[$i]['supporter'] = $users[$ret[$i]['user1id']]['supporter'];
                    }
                }

                unset($ret[$i]['user1id']);
                unset($ret[$i]['user2id']);
            }
        }

        return($ret);
    }

    # This can be overridden in UT.
    public function constructSwiftMessage($id, $toname, $to, $fromname, $from, $subject, $text, $html, $fromuid = NULL, $groupid = NULL, $refmsgs = [])  {
        $_SERVER['SERVER_NAME'] = USER_DOMAIN;
        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom([$from => $fromname])
#            ->setBcc('log@ehibbert.org.uk')
            ->setBody($text);

        try {
            if (strpos($to, ',') !== FALSE) {
                $message->setTo(explode(',', $to));
            } else {
                $message->setTo([$to => $toname]);
            }

            if ($html) {
                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                # Outlook.
                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);
            }

            Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::CHAT, $id);

            $headers = $message->getHeaders();

            if ($refmsgs && count($refmsgs)) {
                $headers->addTextHeader('X-Freegle-Msgids', implode(',', $refmsgs));
            }

            if ($fromuid) {
                $headers->addTextHeader('X-Freegle-From-UID', $fromuid);
            }

            if ($groupid) {
                $headers->addTextHeader('X-Freegle-Group-Volunteer', $groupid);
            }
        } catch (\Exception $e) {
            # Flag the email as bouncing.
            error_log("Exception " . $e->getMessage());
            error_log("Email $to for member #$id invalid, flag as bouncing");
            $this->dbhm->preExec("UPDATE users SET bouncing = 1 WHERE id = ?;", [  $id ]);
            $message = NULL;
        }

        return ($message);
    }

    public function mailer($message, $recip = NULL)
    {
        list ($transport, $mailer) = Mail::getMailer();

        $mailer->send($message);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function createGroupChat($name, $gid)
    {
        try {
            # Duplicates can occur due to timing windows.
            $rc = $this->dbhm->preExec("INSERT INTO chat_rooms (name, chattype, groupid, latestmessage) VALUES (?,?,?, NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), latestmessage = NOW()", [
                $name,
                ChatRoom::TYPE_MOD2MOD,
                $gid
            ]);
            $id = $this->dbhm->lastInsertId();
        } catch (\Exception $e) {
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $myid = Session::whoAmId($this->dbhr, $this->dbhm);

            $this->ourFetch($id, $myid);
            $this->chatroom['groupname'] = $this->getGroupName($gid);
            return ($id);
        } else {
            return (NULL);
        }
    }

    public function ensureAppearInList($id) {
        # Update latestmessage.  This makes sure that the chat will appear in listForUser.
        $this->dbhm->preExec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?;", [
            $id
        ]);

        # Also ensure it's not closed.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
    }

    public function bannedInCommon($user1, $user2) {
        $bannedonall = FALSE;

        $banned = $this->dbhr->preQuery("SELECT groupid FROM users_banned WHERE userid = ?;", [
            $user1
        ]);

        if (count($banned)) {
            $bannedon = array_column($banned, 'groupid');
            #error_log("Banned on " . json_encode($bannedon));

            $user1groups = $this->dbhr->preQuery("SELECT groupid FROM memberships WHERE userid = ?;", [
                $user1
            ]);

            $user1ids = array_column($user1groups, 'groupid');

            #error_log("User1ids " . json_encode($user1ids));

            $user2groups = $this->dbhr->preQuery("SELECT groupid FROM memberships WHERE userid = ?;", [
                $user2
            ]);

            $user2ids = array_column($user2groups, 'groupid');
            #error_log("User2ids " . json_encode($user2ids));
            $inter = array_intersect(array_merge($user1ids, $bannedon), $user2ids);
            #error_log("Inter " . json_encode($inter));

            $bannedIntersect = array_intersect($inter, $bannedon);

            if (count($inter) && count($bannedIntersect) == count($inter)) {
                # They have groups in common and user1 is banned on all of them.  Block.
                $bannedonall = TRUE;
            } else if (!count($user1ids) && count($bannedon)) {
                # User1 isn't even a member of any groups at the moment, but is banned on some.  Block.
                $bannedonall = TRUE;
            }
        }

        return $bannedonall;
    }

    public function createConversation($user1, $user2, $checkonly = FALSE)
    {
        $id = NULL;
        $bannedonall = FALSE;
        $created = FALSE;

        # We use a transaction to close timing windows.
        $this->dbhm->beginTransaction();

        # Find any existing chat.  Who is user1 and who is user2 doesn't really matter - it's a two way chat.
        $sql = "SELECT id, created FROM chat_rooms WHERE (user1 = ? AND user2 = ?) OR (user2 = ? AND user1 = ?) AND chattype = ? FOR UPDATE;";
        $chats = $this->dbhm->preQuery($sql, [
            $user1,
            $user2,
            $user1,
            $user2,
            ChatRoom::TYPE_USER2USER
        ]);

        $rollback = TRUE;

        if (count($chats) > 0) {
            # We have an existing chat.  That'll do nicely.
            if ($checkonly) {
                # Check if we have any messages.
                $msgs = $this->dbhm->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE chatid = ?;", [
                    $chats[0]['id']
                ]);

                $id = $msgs[0]['count'] ? $chats[0]['id'] : NULL;
            } else {
                # Return and bump.
                $id = $chats[0]['id'];
                $this->ensureAppearInList($id);
            }
        } else if (!$checkonly) {
            # We don't have one.
            #
            # If the sender is banned on all the groups they have in common with the recipient, then they shouldn't
            # be able to communicate.
            $s = new Spam($this->dbhr, $this->dbhm);
            $bannedonall = $this->bannedInCommon($user1, $user2) || $s->isSpammerUid($user1) || $s->isSpammerUid($user2);

            if (!$bannedonall) {
                # All good.  Create one.  Duplicates can happen due to timing windows.
                $rc = $this->dbhm->preExec("INSERT INTO chat_rooms (user1, user2, chattype, latestmessage) VALUES (?,?,?, NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), latestmessage = NOW()", [
                    $user1,
                    $user2,
                    ChatRoom::TYPE_USER2USER
                ]);

                if ($rc) {
                    # We created one.  We'll commit below.
                    $id = $this->dbhm->lastInsertId();
                    $rollback = FALSE;
                    $created = TRUE;
                }
            }
        }

        if ($rollback) {
            # We might have worked above or failed; $id is set accordingly.
            $this->dbhm->rollBack();
        } else {
            # We want to commit, and return an id if that worked.
            $rc = $this->dbhm->commit();
            $id = $rc ? $id : NULL;
        }

        if ($id) {
            $myid = Session::whoAmId($this->dbhr, $this->dbhm);

            $this->ourFetch($id, $myid);

            if ($created) {
                # Ensure the two members are in the roster.
                $this->updateRoster($user1, NULL);
                $this->updateRoster($user2, NULL);
            }

            # Poke the (other) member(s) to let them know to pick up the new chat
            $n = new PushNotifications($this->dbhr, $this->dbhm);

            foreach ([$user1, $user2] as $user) {
                if ($myid != $user) {
                    $n->poke($user, [
                        'newroom' => $id
                    ], FALSE);
                }
            }
        }

        return [ $id, $bannedonall ];
    }

    public function createUser2Mod($user1, $groupid)
    {
        $id = NULL;

        # We use a transaction to close timing windows.
        $this->dbhm->beginTransaction();

        # Find any existing chat.
        $sql = "SELECT id FROM chat_rooms WHERE user1 = ? AND groupid = ? AND chattype = ? FOR UPDATE;";
        $chats = $this->dbhm->preQuery($sql, [
            $user1,
            $groupid,
            ChatRoom::TYPE_USER2MOD
        ]);

        $rollback = TRUE;

        # We have an existing chat.  That'll do nicely.
        $id = count($chats) > 0 ? $chats[0]['id'] : NULL;

        if (!$id) {
            # We don't.  Create one.  Duplicates can happen due to timing windows.
            $rc = $this->dbhm->preExec("INSERT INTO chat_rooms (user1, groupid, chattype, latestmessage) VALUES (?,?,?, NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), latestmessage = NOW()", [
                $user1,
                $groupid,
                ChatRoom::TYPE_USER2MOD
            ]);

            if ($rc) {
                # We created one.  We'll commit below.
                $id = $this->dbhm->lastInsertId();
                $rollback = FALSE;
            }
        }

        if ($rollback) {
            # We might have worked above or failed; $id is set accordingly.
            $this->dbhm->rollBack();
        } else {
            # We want to commit, and return an id if that worked.
            $rc = $this->dbhm->commit();
            $id = $rc ? $id : NULL;
        }

        if ($id) {
            $myid = Session::whoAmId($this->dbhr, $this->dbhm);

            $this->ourFetch($id, $myid);

            # Ensure this user is in the roster.
            $this->updateRoster($user1, NULL);

            # Ensure the group mods are in the roster.  We need to do this otherwise for new chats we would not
            # mail them about this message.
            $mods = $this->dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');", [
                $groupid
            ]);

            foreach ($mods as $mod) {
                $sql = "INSERT IGNORE INTO chat_roster (chatid, userid) VALUES (?, ?);";
                $this->dbhm->preExec($sql, [$id, $mod['userid']]);
            }

            # Poke the group mods to let them know to pick up the new chat
            $n = new PushNotifications($this->dbhr, $this->dbhm);

            $n->pokeGroupMods($groupid, [
                'newroom' => $this->id
            ]);
        }

        return ($id);
    }

    public function getPublic($me = NULL, $mepub = NULL, $summary = FALSE)
    {
        $me = $me ? $me : Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        $u1id = Utils::presdef('user1', $this->chatroom, NULL);
        $u2id = Utils::presdef('user2', $this->chatroom, NULL);
        $gid = $this->chatroom['groupid'];
        
        $ret = $this->getAtts($this->publicatts);

        if (Utils::pres('groupid', $ret) && !$summary) {
            $g = Group::get($this->dbhr, $this->dbhm, $ret['groupid']);
            unset($ret['groupid']);
            $ret['group'] = $g->getPublic();
        }

        if (!$summary) {
            if ($u1id) {
                if ($u1id == $myid && $mepub) {
                    $ret['user1'] = $mepub;
                } else {
                    $u = $u1id == $myid ? $me : User::get($this->dbhr, $this->dbhm, $u1id);
                    $ret['user1'] = $u->getPublic(NULL, FALSE, Session::modtools(), FALSE, FALSE, FALSE);

                    if (Utils::pres('group', $ret)) {
                        # As a mod we can see the email
                        $ret['user1']['email'] = $u->getEmailPreferred();
                    }
                }
            }

            if ($u2id) {
                if ($u2id == $myid && $mepub) {
                    $ret['user2'] = $mepub;
                } else {
                    $u = $u2id == $myid ? $me : User::get($this->dbhr, $this->dbhm, $u2id);
                    $ret['user2'] = $u->getPublic(NULL, FALSE, Session::modtools(), FALSE, FALSE, FALSE);

                    if (Utils::pres('group', $ret)) {
                        # As a mod we can see the email
                        $ret['user2']['email'] = $u->getEmailPreferred();
                    }
                }
            }
        }
        
        if (!$summary) {
            # We return whether someone is on the spammer list so that we can warn members.
            $s = new Spam($this->dbhr, $this->dbhm);

            if ($u1id) {
                $ret['user1']['spammer'] = !is_null($s->getSpammerByUserid($u1id));
            }

            if ($u2id) {
                $ret['user2']['spammer'] = !is_null($s->getSpammerByUserid($u2id));
            }
        }

        # Icon for chat.  We assume that any user icons will have been created by this point.
        switch ($this->chatroom['chattype']) {
            case ChatRoom::TYPE_USER2USER:
                if ($this->chatroom['user1'] == $myid) {
                    $ret['icon'] = Utils::pres('u2defaultimage', $this->chatroom) ? ('https://' . USER_DOMAIN . '/defaultprofile.png') : ('https://' . IMAGE_DOMAIN . "/tuimg_" . $this->chatroom['u2imageid']  . ".jpg");
                } else {
                    $ret['icon'] = Utils::pres('u1defaultimage', $this->chatroom) ? ('https://' . USER_DOMAIN . '/defaultprofile.png') : ('https://' . IMAGE_DOMAIN . "/tuimg_" . $this->chatroom['u1imageid'] . ".jpg");
                }
                break;
            case ChatRoom::TYPE_USER2MOD:
                if ($this->chatroom['user1'] == $myid) {
                    $ret['icon'] =  "https://" . IMAGE_DOMAIN . "/gimg_{$this->chatroom['gimageid']}.jpg";
                } else{
                    $ret['icon'] = 'https://' . IMAGE_DOMAIN . "/tuimg_" . $this->chatroom['u1imageid'] . ".jpg";
                }
                break;
            case ChatRoom::TYPE_MOD2MOD:
                $ret['icon'] = "https://" . IMAGE_DOMAIN . "/gimg_{$this->chatroom['gimageid']}.jpg";
                break;
            case ChatRoom::TYPE_GROUP:
                $ret['icon'] = "https://" . IMAGE_DOMAIN . "/gimg_{$this->chatroom['gimageid']}.jpg";
                break;
        }

        $ret['unseen'] = $this->chatroom['unseen'];

        # The name we return is not the one we created it with, which is internal.  Similar code in getName().
        switch ($this->chatroom['chattype']) {
            case ChatRoom::TYPE_USER2USER:
                if ($summary) {
                    # We use the name of the user who isn't us, because that's who we're chatting to.
                    $ret['name'] = $this->getUserName($myid == $u1id ? $u2id : $u1id);
                } else {
                    $ret['name'] = $u1id != $myid ? $ret['user1']['displayname'] : $ret['user2']['displayname'];
                }

                $ret['name'] = User::removeTNGroup($ret['name']);

                break;
            case ChatRoom::TYPE_USER2MOD:
                # If we started it, we're chatting to the group volunteers; otherwise to the user.
                if ($summary) {
                    $ret['name'] = ($u1id == $myid) ? ($this->getGroupName($gid) . " Volunteers") : ($this->getUserName($u1id) . " on " . $this->getGroupName($gid));
                } else {
                    $username = $ret['user1']['displayname'];
                    $username = strlen(trim($username)) > 0 ? $username : 'A freegler';
                    $ret['name'] = $u1id == $myid ? "{$ret['group']['namedisplay']} Volunteers" : "$username on {$ret['group']['nameshort']}";
                }
                
                break;
            case ChatRoom::TYPE_MOD2MOD:
                # Mods chatting to each other.
                $ret['name'] = $summary ? ($this->getGroupName($gid) . " Mods") : "{$ret['group']['namedisplay']} Mods";
                break;
            case ChatRoom::TYPE_GROUP:
                # Members chatting to each other
                $ret['name'] = $summary ? ($this->getGroupName($gid) . " Discussion") : "{$ret['group']['namedisplay']} Discussion";
                break;
        }

        if (!$summary) {
            $refmsgs = $this->dbhr->preQuery("SELECT DISTINCT refmsgid FROM chat_messages INNER JOIN messages ON messages.id = refmsgid AND messages.type IN ('Offer', 'Wanted') WHERE chatid = ? ORDER BY refmsgid DESC;", [$this->id]);
            $ret['refmsgids'] = [];
            foreach ($refmsgs as $refmsg) {
                $ret['refmsgids'][] = $refmsg['refmsgid'];
            }
        }

        # We got the info we need to construct the snippet in the original construct.
        $ret['lastmsg'] = 0;
        $ret['lastdate'] = NULL;
        $ret['snippet'] = '';

        if (Utils::pres('lastmsg', $this->chatroom)) {
            $ret['lastmsg'] = $this->chatroom['lastmsg'];
            $ret['lastdate'] = $this->chatroom['lastdate'];
            $refmsgtype = NULL;

            if ($this->chatroom['chatmsgtype'] == ChatMessage::TYPE_COMPLETED) {
                # Find the type of the message that has completed.
                $types = $this->dbhr->preQuery("SELECT messages.type FROM messages INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id WHERE chat_messages.id = ?;", [
                    $this->chatroom['lastmsg']
                ]);

                foreach ($types as $type) {
                    $refmsgtype = $type['type'];
                }
            }

            $ret['snippet'] = $this->getSnippet($this->chatroom['chatmsgtype'], $this->chatroom['chatmsg'], $refmsgtype);
        }

        if (!$summary) {
            # Count the expected replies.
            $oldest = date("Y-m-d", strtotime("Midnight 31 days ago"));

            $ret['replyexpected'] = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE chatid = ? AND replyexpected = 1 AND replyreceived = 0 AND userid != ? AND chat_messages.date >= '$oldest';", [
                $this->chatroom['id'],
                $myid
            ])[0]['count'];
        }

        return ($ret);
    }

    public function getSnippet($msgtype, $chatmsg, $refmsgtype) {
        switch ($msgtype) {
            case ChatMessage::TYPE_ADDRESS: $ret = 'Address sent...'; break;
            case ChatMessage::TYPE_NUDGE: $ret = 'Nudged'; break;
            case ChatMessage::TYPE_COMPLETED: {
                if ($refmsgtype == Message::TYPE_OFFER) {
                    if ($chatmsg) {
                        $msg = $chatmsg;
                        $msg = $this->splitEmoji($msg);

                        $ret = substr($msg, 0, 30);
                    } else {
                        $ret = 'Item marked as TAKEN';
                    }
                } else {
                    $ret = 'Item marked as RECEIVED...';
                }
                break;
            }
            case ChatMessage::TYPE_PROMISED: $ret = 'Item promised...'; break;
            case ChatMessage::TYPE_RENEGED: $ret = 'Promise cancelled...'; break;
            case ChatMessage::TYPE_IMAGE: $ret = 'Image...'; break;
            default: {
                # We don't want to land in the middle of an encoded emoji otherwise it will display
                # wrongly.
                $msg = $chatmsg;
                $msg = $this->splitEmoji($msg);

                $ret = substr($msg, 0, 30);
                break;
            }
        }
        
        return($ret);
    }
    
    private function getGroupName($gid) {
        return($this->chatroom['groupname']);
    }
    
    private function getUserName($uid) {
        $name = 'A freegler';

        if ($uid == $this->chatroom['user1']) {
            $name = $this->chatroom['u1name'];
        } else if ($uid == $this->chatroom['user2']) {
            $name = $this->chatroom['u2name'];
        }

        return($name);
    }

    public function splitEmoji($msg) {
        $without = preg_replace('/\\\\u.*?\\\\u/', '', $msg);

        # If we have something other than emojis, return that.  Otherwise return the emoji(s) which will be
        # rendered in the client.
        $msg = strlen($without) ? $without : $msg;

        return $msg;
    }

    public function lastSeenForUser($userid)
    {
        # Find if we have any unseen messages.
        if ($this->chatroom['chattype'] == ChatRoom::TYPE_USER2MOD && $userid != $this->chatroom['user1']) {
            # This is a chat between a user and group mods - and we're checking for a user who isn't the member - so
            # must be the mod.  In that case we only return that messages are unseen if they have not been seen by
            # _any_ of the mods.
            $sql = "SELECT MAX(chat_roster.lastmsgseen) AS lastmsgseen FROM chat_roster INNER JOIN chat_rooms ON chat_roster.chatid = chat_rooms.id WHERE chatid = ? AND userid = ?;";
            $counts = $this->dbhr->preQuery($sql, [$this->id, $userid]);
        } else {
            # No fancy business - just get it from the roster.
            $sql = "SELECT chat_roster.lastmsgseen FROM chat_roster INNER JOIN chat_rooms ON chat_roster.chatid = chat_rooms.id WHERE chatid = ? AND userid = ?;";
            $counts = $this->dbhr->preQuery($sql, [$this->id, $userid]);
        }
        return (count($counts) > 0 ? $counts[0]['lastmsgseen'] : NULL);
    }

    public function mailedLastForUser($userid)
    {
        $sql = "UPDATE chat_roster SET lastemailed = NOW(), lastmsgemailed = (SELECT MAX(id) FROM chat_messages WHERE chatid = ?) WHERE userid = ? AND chatid = ?;";
        $this->dbhm->preExec($sql, [
            $this->id,
            $userid,
            $this->id
        ]);
    }

    public function unseenCountForUser($userid, $check = TRUE)
    {
        # Find if we have any unseen messages.  Exclude any pending review.
        $checkq = $check ? (" AND status != '" . ChatRoom::STATUS_CLOSED . "' AND status != '" . ChatRoom::STATUS_BLOCKED . "' ") : '';
        $sql = "SELECT COUNT(*) AS count FROM chat_messages WHERE id > COALESCE((SELECT lastmsgseen FROM chat_roster WHERE chatid = ? AND userid = ? $checkq), 0) AND chatid = ? AND userid != ? AND reviewrequired = 0 AND reviewrejected = 0 AND (processingsuccessful = 1 OR processingrequired = 0);";
        $counts = $this->dbhm->preQuery($sql, [
            $this->id,
            $userid,
            $this->id,
            $userid
        ]);

        return ($counts[0]['count']);
    }

    public function allUnseenForUser($userid, $chattypes, $modtools)
    {
        # Get all unseen messages.  We might have a cached version.
        $chatids = $this->listForUser($modtools, $userid, $chattypes, NULL, NULL, ChatRoom::ACTIVELIM);

        $ret = [];

        if ($chatids) {
            $idq = implode(',', $chatids);
            $sql = "SELECT chat_messages.* FROM chat_messages LEFT JOIN chat_roster ON chat_roster.chatid = chat_messages.chatid AND chat_roster.userid = ? WHERE chat_messages.chatid IN ($idq) AND chat_messages.userid != ? AND reviewrequired = 0 AND reviewrejected = 0 AND (processingsuccessful = 1 OR processingrequired = 0) AND chat_messages.id > COALESCE(chat_roster.lastmsgseen, 0);";
            $ret = $this->dbhr->preQuery($sql, [ $userid, $userid]);
        }

        return ($ret);
    }

    public function countAllUnseenForUser($userid, $chattypes)
    {
        $chatids = $this->listForUser(Session::modtools(), $userid, $chattypes);

        $ret = 0;

        if ($chatids) {
            $activesince = date("Y-m-d", strtotime(ChatRoom::ACTIVELIM));
            $idq = implode(',', $chatids);
            $sql = "SELECT COUNT(chat_messages.id) AS count FROM chat_messages LEFT JOIN chat_roster ON chat_roster.chatid = chat_messages.chatid AND chat_roster.userid = ? WHERE chat_messages.chatid IN ($idq) AND chat_messages.userid != ? AND reviewrequired = 0 AND reviewrejected = 0 AND (processingsuccessful = 1 OR processingrequired = 0) AND chat_messages.id > COALESCE(chat_roster.lastmsgseen, 0) AND chat_messages.date >= '$activesince';";
            $ret = $this->dbhr->preQuery($sql, [ $userid, $userid ])[0]['count'];
        }

        return ($ret);
    }

    public function updateMessageCounts() {
        # We store some information about the messages in the room itself.  We try to avoid duplicating information
        # like this, because it's asking for it to get out of step, but it means we can efficiently find the chat
        # rooms for a user in listForUser.
        $unheld = $this->dbhr->preQuery("SELECT CASE WHEN reviewrequired = 0 AND reviewrejected = 0 AND (processingsuccessful = 1 OR processingrequired = 0) THEN 1 ELSE 0 END AS valid, COUNT(*) AS count FROM chat_messages WHERE chatid = ? GROUP BY (reviewrequired = 0 AND reviewrejected = 0) ORDER BY valid ASC;", [
            $this->id
        ]);

        $validcount = 0;
        $invalidcount = 0;

        foreach ($unheld as $un) {
            $validcount = ($un['valid'] == 1) ? ++$validcount : $validcount;
            $invalidcount = ($un['valid'] == 0) ? ++$invalidcount : $invalidcount;
        }

        $dates = $this->dbhr->preQuery("SELECT MAX(date) AS maxdate FROM chat_messages WHERE chatid = ?;", [
            $this->id
        ], FALSE);

        if ($dates[0]['maxdate']) {
            $this->dbhm->preExec("UPDATE chat_rooms SET msgvalid = ?, msginvalid = ?, latestmessage = ? WHERE id = ?;", [
                $validcount,
                $invalidcount,
                $dates[0]['maxdate'],
                $this->id
            ]);
        } else {
            # Leave date untouched to allow chat to age out.
            $this->dbhm->preExec("UPDATE chat_rooms SET msgvalid = ?, msginvalid = ? WHERE id = ?;", [
                $validcount,
                $invalidcount,
                $this->id
            ]);
        }
    }

    public function listForUser($modtools, $userid, $chattypes = NULL, $search = NULL, $chatid = NULL, $activelim = NULL)
    {
        if (is_null($activelim)) {
            $activelim = $modtools ? ChatRoom::ACTIVELIM_MT : ChatRoom::ACTIVELIM;
        }

        $ret = [];
        $chatq = $chatid ? "chat_rooms.id = $chatid AND " : '';

        if ($userid) {
            # The chats we can see are:
            # - either for a group (possibly a modonly one)
            # - a conversation between two users that we have not closed
            # - (for user2user or user2mod) active in last 31 days
            #
            # A single query that handles this would be horrific, and having tried it, is also hard to make efficient.  So
            # break it down into smaller queries that have the dual advantage of working quickly and being comprehensible.
            #
            # We need the memberships.  We used to use a temp table but we can't use a temp table multiple times within
            # the same query, and we've combined the queries into a single one using UNION for performance.  We'd
            # like to use WITH but that isn't available until MySQL 8.  So instead we repeat this query a lot and
            # hope that the optimiser spots it.  It's still faster than multiple separate queries.
            #
            # We want to know if this is an active chat for us - always the case for groups where we have a member role,
            # but for mods we might have marked ourselves as a backup on the group.
            $t1 = "(SELECT groupid, role, role = 'Member' OR ((role IN ('Owner', 'Moderator') AND (settings IS NULL OR LOCATE('\"active\"', settings) = 0 OR LOCATE('\"active\":1', settings) > 0))) AS active FROM memberships WHERE userid = $userid) t1 ";

            $activesince = $chatid ? '1970-01-01' : date("Y-m-d", strtotime($activelim));

            # We don't want to see non-empty chats where all the messages are held for review, because they are likely to
            # be spam.
            $countq = " AND (chat_rooms.msgvalid + chat_rooms.msginvalid = 0 OR chat_rooms.msgvalid > 0) ";

            # We don't want to see chats where you are a backup mod, unless we're specifically searching.
            $activeq = ($chatid || $search) ? '' : ' AND active ';

            $sql = '';

            # We only need a few attributes, and this speeds it up.  No really, I've measured it.
            $atts = 'chat_rooms.id, chat_rooms.chattype, chat_rooms.groupid';

            if (!$chattypes || in_array(ChatRoom::TYPE_MOD2MOD, $chattypes)) {
                # We want chats marked by groupid for which we are an active mod.
                $thissql = "SELECT $atts FROM chat_rooms LEFT JOIN chat_roster ON chat_roster.userid = $userid AND chat_rooms.id = chat_roster.chatid INNER JOIN $t1 ON chat_rooms.groupid = t1.groupid WHERE $chatq t1.role IN ('Moderator', 'Owner') $activeq AND chattype = 'Mod2Mod' AND (status IS NULL OR status != 'Closed') $countq";
                $sql = $sql == '' ? $thissql : "$sql UNION $thissql";
                #error_log("Mod2Mod chats $sql, $userid");
            }

            if (!$chattypes || in_array(ChatRoom::TYPE_USER2MOD, $chattypes)) {
                # If we're on ModTools then we want User2Mod chats for our group.
                #
                # If we're on the user site then we only want User2Mod chats where we are a user.
                $thissql = $modtools ?
                    "SELECT $atts FROM chat_rooms LEFT JOIN chat_roster ON chat_roster.userid = $userid AND chat_rooms.id = chat_roster.chatid INNER JOIN $t1 ON chat_rooms.groupid = t1.groupid WHERE (t1.role IN ('Owner', 'Moderator') OR chat_rooms.user1 = $userid) $activeq AND latestmessage >= '$activesince' AND chattype = 'User2Mod' AND (status IS NULL OR status != 'Closed')" :
                    "SELECT $atts FROM chat_rooms LEFT JOIN chat_roster ON chat_roster.userid = $userid AND chat_rooms.id = chat_roster.chatid WHERE $chatq user1 = $userid AND chattype = 'User2Mod' AND latestmessage >= '$activesince' AND (status IS NULL OR status != 'Closed') $countq";
                $sql = $sql == '' ? $thissql : "$sql UNION $thissql";
            }

            if (!$chattypes || in_array(ChatRoom::TYPE_USER2USER, $chattypes)) {
                # We want chats where we are one of the users.  If the chat is closed or blocked we don't want to see
                # it unless we're on MT.
                $statusq = $modtools ? '' : "AND (status IS NULL OR status NOT IN ('Closed', 'Blocked'))";
                $thissql = "SELECT $atts FROM chat_rooms LEFT JOIN chat_roster ON chat_roster.userid = $userid AND chat_rooms.id = chat_roster.chatid WHERE $chatq user1 = $userid AND chattype = 'User2User' AND latestmessage >= '$activesince' $statusq $countq";
                $thissql .= " UNION SELECT $atts FROM chat_rooms LEFT JOIN chat_roster ON chat_roster.userid = $userid AND chat_rooms.id = chat_roster.chatid WHERE $chatq user2 = $userid AND chattype = 'User2User' AND latestmessage >= '$activesince' $statusq $countq";
                $sql = $sql == '' ? $thissql : "$sql UNION $thissql";
                #error_log("User chats $sql, $userid");
            }

            if (Session::modtools() && (!$chattypes || in_array(ChatRoom::TYPE_GROUP, $chattypes))) {
                # We want chats marked by groupid for which we are a member.  This is mod-only function.
                $thissql = "SELECT $atts FROM chat_rooms INNER JOIN $t1 ON chattype = 'Group' AND chat_rooms.groupid = t1.groupid LEFT JOIN chat_roster ON chat_roster.userid = $userid AND chat_rooms.id = chat_roster.chatid WHERE $chatq (status IS NULL OR status != 'Closed') $countq";
                #error_log("Group chats $sql, $userid");
                $sql = $sql == '' ? $thissql : "$sql UNION $thissql";
                #error_log("Add " . count($rooms) . " group chats using $sql");
            }

            $rooms = $this->dbhr->preQuery($sql);

            if (count($rooms) > 0) {
                # We might have quite a lot of chats - speed up by reducing user fetches.
                $me = Session::whoAmI($this->dbhr, $this->dbhm);

                foreach ($rooms as $room) {
                    $show = TRUE;

                    if ($search) {
                        # We want to apply a search filter on the name.  We do a special query to get the name, because
                        # calling getPublic is expensive.
                        $name = $this->getName($room['id'], $me ? $me->getId() : NULL);

                        if (stripos($name, $search) === FALSE) {
                            # We didn't get a match easily.  Now we have to search in the messages.
                            $searchq = $this->dbhr->quote("%$search%");
                            $sql = "SELECT chat_messages.id FROM chat_messages LEFT OUTER JOIN messages ON messages.id = chat_messages.refmsgid WHERE chatid = {$room['id']} AND (chat_messages.message LIKE $searchq OR messages.subject LIKE $searchq) LIMIT 1;";
                            $msgs = $this->dbhr->preQuery($sql);

                            $show = count($msgs) > 0;
                        }
                    }

                    if ($show && $room['chattype'] == ChatRoom::TYPE_MOD2MOD && $room['groupid']) {
                        # See if the group allows chat.
                        $g = Group::get($this->dbhr, $this->dbhm, $room['groupid']);
                        $show = $g->getSetting('showchat', TRUE);
                    }

                    if ($show) {
                        $ret[] = $room['id'];
                    }
                }
            }
        }

        return (count($ret) == 0 ? NULL : $ret);
    }

    public function getName($chatid, $myid) {
        # Similar code in getPublic.
        $ret = NULL;

        $rooms = $this->dbhr->preQuery("SELECT * FROM chat_rooms WHERE id = ?;", [
            $chatid
        ]);

        foreach ($rooms as $room) {
            switch ($room['chattype']) {
                case ChatRoom::TYPE_USER2USER:
                    $uid = $room['user1'] != $myid ? $room['user1'] : $room['user2'];
                    $u = User::get($this->dbhr, $this->dbhm, $uid);
                    $ret = $u->getName();
                    break;
                case ChatRoom::TYPE_USER2MOD:
                    # If we started it, we're chatting to the group volunteers; otherwise to the user.
                    $u = new User($this->dbhr, $this->dbhm, $room['user1']);
                    $username = $u->getName();
                    $username = strlen(trim($username)) > 0 ? $username : 'A freegler';
                    $g = Group::get($this->dbhr, $this->dbhm, $room['groupid']);
                    $ret = $room['user1'] == $myid ? "{$g->getName()} Volunteers" : "$username on {$g->getName()}";
                    break;
                case ChatRoom::TYPE_MOD2MOD:
                    # Mods chatting to each other.
                    $g = Group::get($this->dbhr, $this->dbhm, $room['groupid']);
                    $ret = $g->getName() . " Mods";
                    break;
                case ChatRoom::TYPE_GROUP:
                    # Members chatting to each other
                    $g = Group::get($this->dbhr, $this->dbhm, $room['groupid']);
                    $ret = $g->getName() . " Discussion";
                    break;
            }
        }

        return User::removeTNGroup($ret);
    }

    public function canSee($userid, $checkmod = TRUE)
    {
        if (!$this->id) {
            # It's an invalid id.
            $cansee = FALSE;
        } else {
            if ($userid == $this->chatroom['user1'] || $userid == $this->chatroom['user2']) {
                # It's one of ours - so we can see it.
                $cansee = TRUE;
            } else {
                # If we ourselves have rights to see all chats, then we can speed things up by noticing that rather
                # than doing more queries.
                $me = Session::whoAmI($this->dbhr, $this->dbhm);

                if ($me && $me->isAdminOrSupport()) {
                    $cansee = TRUE;
                } else {
                    # It might be a group chat which we can see.  We reuse the code that lists chats and checks access,
                    # but using a specific chatid to save time.
                    $rooms = $this->listForUser(Session::modtools(), $userid, [$this->chatroom['chattype']], NULL, $this->id);
                    #error_log("CanSee $userid, {$this->id}, " . var_export($rooms, TRUE));
                    $cansee = $rooms ? in_array($this->id, $rooms) : FALSE;
                }
            }

            if (!$cansee && $checkmod) {
                # If we can't see it by right, but we are a mod for the users in the chat, then we can see it.
                #error_log("$userid can't see {$this->id} of type {$this->chatroom['chattype']}");
                $me = Session::whoAmI($this->dbhr, $this->dbhm);

                if ($me) {
                    if ($me->isAdminOrSupport() ||
                        ($this->chatroom['chattype'] == ChatRoom::TYPE_USER2USER &&
                            ($me->moderatorForUser($this->chatroom['user1']) ||
                                $me->moderatorForUser($this->chatroom['user2']))) ||
                        ($this->chatroom['chattype'] == ChatRoom::TYPE_USER2MOD &&
                            $me->moderatorForUser($this->chatroom['user1']))
                    ) {
                        $cansee = TRUE;
                    }
                }
            }
        }

        return ($cansee);
    }

    public function upToDate($userid) {
        $msgs = $this->dbhr->preQuery("SELECT MAX(id) AS max FROM chat_messages WHERE chatid = ?;", [ $this->id ]);
        foreach ($msgs as $msg) {
            #error_log("upToDate: Set max to {$msg['max']} for $userid in room {$this->id} ");
            $this->dbhm->preExec("INSERT INTO chat_roster (chatid, userid, lastmsgseen, lastmsgemailed, lastemailed) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE lastmsgseen = ?, lastmsgemailed = ?, lastemailed = NOW();",
                [
                    $this->id,
                    $userid,
                    $msg['max'],
                    $msg['max'],
                    $msg['max'],
                    $msg['max']
                ]);
        }
    }

    public function upToDateAll($myid, $chattypes = NULL) {
        $chatids = $this->listForUser(Session::modtools(), $myid, $chattypes);
        $found = FALSE;

        if ($chatids) {
            # Find current values.  This allows us to filter out many updates.
            $currents = count($chatids) ? $this->dbhr->preQuery("SELECT chatid, lastmsgseen, (SELECT MAX(id) AS max FROM chat_messages WHERE chatid = chat_roster.chatid) AS maxmsg FROM chat_roster WHERE userid = ? AND chatid IN (" . implode(',', $chatids) . ");", [
                $myid
            ]) : [];

            foreach ($chatids as $chatid) {
                $found = FALSE;

                foreach ($currents as $current) {
                    if ($current['chatid'] == $chatid) {
                        # We already have a roster entry.
                        $found = TRUE;

                        if ($current['maxmsg'] > $current['lastmsgseen']) {
                            $this->dbhm->preExec("UPDATE chat_roster SET lastmsgseen = ?, lastmsgemailed = ?, lastemailed = NOW() WHERE chatid = ? AND userid = ?;", [
                                $current['maxmsg'],
                                $current['maxmsg'],
                                $chatid,
                                $myid
                            ]);
                        }
                    }
                }

                if (!$found) {
                    # We don't currently have one.  Add it; include duplicate processing for timing window.
                    $max = $this->dbhr->preQuery("SELECT MAX(id) AS max FROM chat_messages WHERE chatid = ?;", [
                        $chatid
                    ]);

                    $this->dbhm->preExec("INSERT INTO chat_roster (chatid, userid, lastmsgseen, lastmsgemailed, lastemailed) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE lastmsgseen = ?, lastmsgemailed = ?, lastemailed = NOW();",
                                         [
                                             $chatid,
                                             $myid,
                                             $max[0]['max'],
                                             $max[0]['max'],
                                             $max[0]['max'],
                                             $max[0]['max'],
                                         ]);
                }
            }
        }

        return $found;
    }

    public function updateRoster($userid, $lastmsgseen, $status = ChatRoom::STATUS_ONLINE, $allowBackwards = FALSE)
    {
        # We have a unique key, and an update on current timestamp.
        #
        # Don't want to log these - lots of them.
        #error_log("updateRoster: Add $userid into {$this->id}");
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);

        $this->dbhm->preExec("INSERT INTO chat_roster (chatid, userid, lastip) VALUES (?,?,?) ON DUPLICATE KEY UPDATE lastip = ?;",
            [
                $this->id,
                $userid,
                $userid == $myid ? Utils::presdef('REMOTE_ADDR', $_SERVER, NULL) : NULL,
                $userid == $myid ? Utils::presdef('REMOTE_ADDR', $_SERVER, NULL) : NULL,
            ],
            FALSE);

        if ($status == ChatRoom::STATUS_CLOSED || $status == ChatRoom::STATUS_BLOCKED) {
            # The Closed and Blocked statuses are special - they're per-room.  So we need to set it.  Take care
            # not to overwrite Blocked with Closed.
            $this->dbhm->preExec("UPDATE chat_roster SET status = ?, date = NOW() WHERE chatid = ? AND userid = ? AND (status IS NULL OR status != ?);", [
                $status,
                $this->id,
                $userid,
                ChatRoom::STATUS_BLOCKED
            ], FALSE);

            if ($status == ChatRoom::STATUS_BLOCKED) {
                $other = $this->getPrivate('user1') == $userid ? $this->getPrivate('user2') : $this->getPrivate('user1');
                $promises = $this->dbhr->preQuery("SELECT messages_promises.msgid FROM messages 
         INNER JOIN messages_promises ON messages_promises.msgid = messages.id 
         WHERE fromuser = ? AND messages_promises.userid = ?", [ $userid, $other ]);

                foreach ($promises as $promise) {
                    $m = new Message($this->dbhr, $this->dbhm, $promise['msgid']);
                    $m->renege($other);
                }
            }
        } else if ($status == ChatRoom::STATUS_ONLINE) {
            # The current status might not be online; if it's not, we need to update it.  Query first to avoid an
            # unnecessary update which is bad for the cluster.
            $current = $this->dbhr->preQuery("SELECT status FROM chat_roster WHERE chatid = ? AND userid = ?;", [
                $this->id,
                $userid
            ]);

            if (count($current) && $current[0]['status'] != ChatRoom::STATUS_ONLINE) {
                $this->dbhm->preExec("UPDATE chat_roster SET status = ? WHERE chatid = ? AND userid = ?;", [
                    ChatRoom::STATUS_ONLINE,
                    $this->id,
                    $userid
                ], FALSE);
            }
        }

        if ($lastmsgseen === 0) {
            # This can happen if we are marking the only message in a chat as unread.
            $this->dbhm->preExec("UPDATE chat_roster SET lastmsgseen = NULL WHERE chatid = ? AND userid = ?;", [
                $this->id,
                $userid,
            ], FALSE);
        } else if ($lastmsgseen && !is_nan($lastmsgseen)) {
            # Update the last message seen - taking care not to go backwards, which can happen if we have multiple
            # windows open.
            $backq = $allowBackwards ? " AND ? " : " AND (lastmsgseen IS NULL OR lastmsgseen < ?)";
            $rc = $this->dbhm->preExec("UPDATE chat_roster SET lastmsgseen = ?, lastmsgemailed = ? WHERE chatid = ? AND userid = ? $backq;", [
                $lastmsgseen,
                $lastmsgseen,
                $this->id,
                $userid,
                $lastmsgseen
            ], FALSE);

            #error_log("Update roster $userid chat {$this->id} $rc last seen $lastmsgseen affected " . $this->dbhm->rowsAffected());
            #error_log("UPDATE chat_roster SET lastmsgseen = $lastmsgseen WHERE chatid = {$this->id} AND userid = $userid AND (lastmsgseen IS NULL OR lastmsgseen < $lastmsgseen))");
            if ($rc && $this->dbhm->rowsAffected()) {
                # We have updated our last seen.  Notify ourselves because we might have multiple devices which
                # have counts/notifications which need updating.
                $n = new PushNotifications($this->dbhr, $this->dbhm);
                #error_log("Update roster for $userid set last seen $lastmsgseen from {$_SERVER['REMOTE_ADDR']}");
                #error_log("Roster notify $userid");
                $n->notify($userid, Session::modtools());
            }

            #error_log("UPDATE chat_roster SET lastmsgseen = $lastmsgseen WHERE chatid = {$this->id} AND userid = $userid AND (lastmsgseen IS NULL OR lastmsgseen < $lastmsgseen);");
            # Now we want to check whether to check whether this message has been seen by everyone in this chat.  If it
            # has, we flag it as seen by all, which speeds up our checking for which email notifications to send out.
            $sql = "SELECT COUNT(*) AS count FROM chat_roster WHERE chatid = ? AND (lastmsgseen IS NULL OR lastmsgseen < ?);";
            #error_log("Check for unseen $sql, {$this->id}, $lastmsgseen");

            $unseens = $this->dbhm->preQuery($sql,
                [
                    $this->id,
                    $lastmsgseen
                ]);

            if ($unseens[0]['count'] == 0) {
                $this->seenByAll($lastmsgseen);
            }
        }
    }

    public function seenByAll($lastmsgseen) {
        $sql = "UPDATE chat_messages SET seenbyall = 1 WHERE chatid = ? AND id <= ?;";
        $this->dbhm->preExec($sql, [$this->id, $lastmsgseen]);
    }

    public function getRoster()
    {
        $mysqltime = date("Y-m-d H:i:s", strtotime("3600 seconds ago"));
        $sql = "SELECT TIMESTAMPDIFF(SECOND, users.lastaccess, NOW()) AS secondsago, chat_roster.* FROM chat_roster INNER JOIN users ON users.id = chat_roster.userid WHERE `chatid` = ? AND `date` >= ? ORDER BY COALESCE(users.fullname, users.firstname, users.lastname);";
        $roster = $this->dbhr->preQuery($sql, [$this->id, $mysqltime]);

        foreach ($roster as &$rost) {
            $u = User::get($this->dbhr, $this->dbhm, $rost['userid']);
            if ($rost['status'] != ChatRoom::STATUS_CLOSED) {
                # This is an active chat room.  We determine the status from the last access time for the user,
                # which is updated regularly in api.php.
                #
                # TODO This means some states in the room status are ignored.  This could be confusing looking at
                # the DB.
                if ($rost['secondsago'] < 60) {
                    $rost['status'] = ChatRoom::STATUS_ONLINE;
                } else if ($rost['secondsago'] < 600) {
                    $rost['status'] = ChatRoom::STATUS_AWAY;
                }
            }

            $rost['user'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE);
        }

        return ($roster);
    }

    public function pokeMembers()
    {
        # Poke members of a chat room.
        $data = [
            'roomid' => $this->id
        ];

        $userids = [];
        $group = NULL;
        $mods = FALSE;

        switch ($this->chatroom['chattype']) {
            case ChatRoom::TYPE_USER2USER:
                # Poke both users.
                $userids[] = $this->chatroom['user1'];
                $userids[] = $this->chatroom['user2'];
                break;
            case ChatRoom::TYPE_USER2MOD:
                # Poke the initiator and all group mods.
                $userids[] = $this->chatroom['user1'];
                $mods = TRUE;
                break;
            case ChatRoom::TYPE_MOD2MOD:
                # If this is a group chat we poke all mods.
                $mods = TRUE;
                break;
        }


        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $count = 0;

        #error_log("Chat #{$this->id} Poke mods $mods users " . var_export($userids, TRUE));

        foreach ($userids as $userid) {
            # We only want to poke users who have a group membership; if they don't, then we shouldn't annoy them.
            $pu = User::get($this->dbhr, $this->dbhm, $userid);
            if (count($pu->getMemberships())  > 0) {
                #error_log("Poke {$rost['userid']} for {$this->id}");
                $n->poke($userid, $data, $mods);
                $count++;
            }
        }

        if ($mods) {
            $count += $n->pokeGroupMods($this->chatroom['groupid'], $data);
        }

        return ($count);
    }

    public function notifyMembers($name, $message, $excludeuser = NULL, $modstoo = FALSE)
    {
        # Notify members of a chat room via:
        # - Facebook
        # - push
        $fduserids = [];
        $mtuserids = [];
        #error_log("Notify $message exclude $excludeuser");

        switch ($this->chatroom['chattype']) {
            case ChatRoom::TYPE_USER2USER:
                # Notify both users.
                $fduserids[] = $this->chatroom['user1'];
                $fduserids[] = $this->chatroom['user2'];

                if ($modstoo) {
                    # Notify active mods of any groups that the recipient is a member of.
                    $recip = $this->chatroom['user1'] == $excludeuser ? $this->chatroom['user2'] : $this->chatroom['user1'];
                    $u = User::get($this->dbhr, $this->dbhm, $recip);
                    $groupids = array_column($u->getMemberships(), 'id');

                    if (count($groupids)) {
                        $mods = $this->dbhr->preQuery("SELECT DISTINCT userid, settings FROM memberships WHERE groupid IN (" . implode(',', $groupids) . ") AND role IN (?, ?)", [
                            User::ROLE_MODERATOR,
                            User::ROLE_OWNER
                        ]);

                        foreach ($mods as $mod) {
                            if (!Utils::pres('settings', $mod) || Utils::pres('active', json_decode($mod['settings']))) {
                                $mtuserids[] = $mod['userid'];
                            }
                        }
                    }
                }
                break;
            case ChatRoom::TYPE_USER2MOD:
                # Notify the initiator and the groups mods.
                $fduserids[] = $this->chatroom['user1'];
                $g = Group::get($this->dbhr, $this->dbhm, $this->chatroom['groupid']);
                $mtuserids = $g->getMods();
                break;
        }

        # First Facebook.  Truncates after 120.  Only notify FD users for this; mods have plenty of other notifications.
        $url = "chat/{$this->id}";
        $text = $name . " wrote: " . $message;
        $text = strlen($text) > 120 ? (substr($text, 0, 117) . '...') : $text;

        $f = new Facebook($this->dbhr, $this->dbhm);
        $count = 0;

        foreach ($fduserids as $userid) {
            # We only want to notify users who have a group membership; if they don't, then we shouldn't annoy them.
            $u = User::get($this->dbhr, $this->dbhm, $userid);
            if ($u->isModerator() || count($u->getMemberships())  > 0) {
                if ($userid != $excludeuser) {
                    #error_log("Poke {$rost['userid']} for {$this->id}");
                    if ($u->notifsOn(User::NOTIFS_FACEBOOK)) {
                        $logins = $this->dbhr->preQuery("SELECT * FROM users_logins WHERE userid = ? AND type = ?;", [
                            $userid,
                            User::LOGIN_FACEBOOK
                        ]);

                        foreach ($logins as $login) {
                            if (is_numeric($login['uid'])) {
                                $f->notify($login['uid'], $text, $url);
                            }
                        }
                    }

                    $count++;
                }
            }
        }

        # Now Push notifications, for both FD and MT.
        $n = new PushNotifications($this->dbhr, $this->dbhm);
        foreach ($fduserids as $userid) {
            if ($userid != $excludeuser) {
                #error_log("Chat notify FD $userid");
                $n->notify($userid, FALSE);
            }
        }

        foreach ($mtuserids as $userid) {
            if ($userid != $excludeuser) {
                #error_log("Chat notify MT $userid");
                $n->notify($userid, TRUE);
            }
        }

        return ($count);
    }

    public function getMessagesForReview(User $user, $groupid, &$ctx)
    {
        # We want the messages for review for any group where we are a mod and the recipient of the chat message is
        # a member, or where the recipient is on no groups and the sender is on one of ours.
        #
        # The order here matches that in ChatMessage::getReviewCountByGroup.
        $userid = $user->getId();
        $msgid = $ctx ? intval($ctx['msgid']) : 0;
        if ($groupid) {
            $groupids = [];
        } else {
            $allmods = $user->getModeratorships();
            $groupids = [];

            foreach ($allmods as $mod) {
                if ($user->activeModForGroup($mod)) {
                    $groupids[] = $mod;
                }
            }
        }
        $groupq = implode(',', $groupids);

        $sql = "SELECT DISTINCT chat_messages.id, chat_messages.chatid, chat_messages.userid, chat_messages.reportreason, chat_messages_byemail.msgid, m1.settings AS m1settings, m1.groupid, m2.groupid AS groupidfrom, chat_messages_held.userid AS heldby, chat_messages_held.timestamp, chat_rooms.user1, chat_rooms.user2, m1.added
FROM chat_messages
LEFT JOIN chat_messages_held ON chat_messages.id = chat_messages_held.msgid
LEFT JOIN chat_messages_byemail ON chat_messages_byemail.chatmsgid = chat_messages.id
INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid
INNER JOIN memberships m1 ON m1.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END) AND m1.groupid IN ($groupq)
LEFT JOIN memberships m2 ON m2.userid = chat_messages.userid AND m2.groupid IN ($groupq)
INNER JOIN `groups` ON m1.groupid = groups.id AND groups.type = ?
WHERE chat_messages.id > ?
UNION
SELECT chat_messages.id, chat_messages.chatid, chat_messages.userid, chat_messages.reportreason, chat_messages_byemail.msgid, m1.settings AS m1settings, m1.groupid, m2.groupid AS groupidfrom, chat_messages_held.userid AS heldby, chat_messages_held.timestamp, chat_rooms.user1, chat_rooms.user2, m1.added
FROM chat_messages
LEFT JOIN chat_messages_held ON chat_messages.id = chat_messages_held.msgid
LEFT JOIN chat_messages_byemail ON chat_messages_byemail.chatmsgid = chat_messages.id
INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid
LEFT JOIN memberships m1 ON m1.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END)
INNER JOIN memberships m2 ON m2.userid = chat_messages.userid AND m2.groupid IN ($groupq)
WHERE chat_messages.id > ? AND m1.id IS NULL
ORDER BY id, added, groupid ASC;";
        $msgs = $this->dbhr->preQuery($sql, [Group::GROUP_FREEGLE, $msgid, $msgid]);
        $ret = [];

        $ctx = $ctx ? $ctx : [];

        # We can get multiple copies of the same chat due to the join.
        $processed = [];

        # Get all the users we might need.
        $uids = array_filter(array_unique(array_merge(
            array_column($msgs, 'heldby'),
            array_column($msgs, 'userid'),
            array_column($msgs, 'user1'),
            array_column($msgs, 'user2')
        )));

        $u = new User($this->dbhr, $this->dbhm);
        $userlist = $u->getPublicsById($uids, NULL, FALSE, TRUE, FALSE, FAlSE, FALSE, FALSE);

        foreach ($msgs as $msg) {
            # Return whether we're an active or not - client can filter.  However we could have two copies of the
            # same message, which is visible on one group because we're an active mod, and another group because we're
            # not.  We want to ensure that we return the active one so that the client pays attention to it.
            $m1settings = json_decode($msg['m1settings']);

            if (!Utils::pres($msg['id'], $processed) || Utils::pres('active', $m1settings)) {
                $processed[$msg['id']] = TRUE;

                $m = new ChatMessage($this->dbhr, $this->dbhm, $msg['id']);
                $thisone = $m->getPublic(TRUE, $userlist);

                if (Utils::pres('heldby', $msg)) {
                    $u = User::get($this->dbhr, $this->dbhm, $msg['heldby']);
                    $thisone['held'] = [
                        'id' => $u->getId(),
                        'name' => $u->getName(),
                        'timestamp' => Utils::ISODate($msg['timestamp']),
                        'email' => $u->getEmailPreferred()
                    ];

                    unset($thisone['heldby']);
                }

                # To avoid fetching the users again, ask for a summary and then fill them in from our in-hand copy.
                $r = new ChatRoom($this->dbhr, $this->dbhm, $msg['chatid']);
                $thisone['chatroom'] = $r->getPublic(NULL, NULL, TRUE);
                $u1id = Utils::presdef('user1', $thisone['chatroom'], NULL);
                $u2id = Utils::presdef('user2', $thisone['chatroom'], NULL);
                $thisone['chatroom']['user1'] = $u1id ? $userlist[$u1id] : NULL;
                $thisone['chatroom']['user2'] = $u2id ? $userlist[$u2id] : NULL;

                $thisone['fromuser'] = $userlist[$msg['userid']];

                $touserid = $msg['userid'] == $thisone['chatroom']['user1']['id'] ? $thisone['chatroom']['user2']['id'] : $thisone['chatroom']['user1']['id'];
                $thisone['touser'] = $userlist[$touserid];

                $g = Group::get($this->dbhr, $this->dbhm, $msg['groupid']);
                $thisone['group'] = $g->getPublic();

                if ($msg['groupidfrom']) {
                    $g = Group::get($this->dbhr, $this->dbhm, $msg['groupidfrom']);
                    $thisone['groupfrom'] = $g->getPublic();
                }

                $thisone['date'] = Utils::ISODate($thisone['date']);
                $thisone['msgid'] = $msg['msgid'];

                $thisone['reviewreason'] = $msg['reportreason'];

                if ($thisone['reviewreason'] == ChatMessage::REVIEW_SPAM) {
                    # Pass this through the spam checks again to see if we can get a more detailed reason.
                    $s = new Spam($this->dbhr, $this->dbhm);
                    list ($spam, $reason, $text) = $s->checkSpam($thisone['message'], [ Spam::ACTION_SPAM, Spam::ACTION_REVIEW ]);

                    if ($spam) {
                        $thisone['reviewreason'] = "$reason $text";
                    } else {
                        list ($spam, $reason, $text) = $s->checkSpam($thisone['fromuser']['displayname'], [ Spam::ACTION_SPAM ]);

                        if ($spam) {
                            $thisone['reviewreason'] = "$reason $text";
                        } else
                        {
                            $reason = $s->checkReview($thisone['message'], TRUE);

                            if ($reason)
                            {
                                $thisone['reviewreason'] = $reason;
                            }
                        }
                    }
                }


                $ctx['msgid'] = $msg['id'];

                $ret[] = $thisone;
            }
        }

        return ($ret);
    }

    public function getMessages($limit = 100, $seenbyall = NULL, &$ctx = NULL, $refmsgsummary = FALSE)
    {
        $limit = intval($limit);
        $ctxq = $ctx ? (" AND chat_messages.id < " . intval($ctx['id']) . " ") : '';
        $seenfilt = is_null($seenbyall) ? '' : " AND seenbyall = $seenbyall ";

        # We do a join with the users table so that we can get the minimal information we need in a single query
        # rather than querying for each user by creating a User object.  Similarly, we fetched all message attributes
        # so that we can pass the fetched attributes into the constructor for each ChatMessage below.
        #
        # This saves us a lot of DB operations.
        $emailq1 = Session::modtools() ? ",chat_messages_byemail.msgid AS bymailid" : '';
        $emailq2 = Session::modtools() ? "LEFT JOIN chat_messages_byemail ON chat_messages_byemail.chatmsgid = chat_messages.id" : '';

        $sql = "SELECT chat_messages.*,
                users.settings,
                users_images.id AS userimageid, users_images.url AS userimageurl, users.systemrole, CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS userdisplayname
                $emailq1
                FROM chat_messages INNER JOIN users ON users.id = chat_messages.userid
                LEFT JOIN users_images ON users_images.userid = users.id 
                $emailq2
                WHERE chatid = ? $seenfilt $ctxq ORDER BY chat_messages.id DESC LIMIT $limit;";
        $msgs = $this->dbhr->preQuery($sql, [$this->id]);
        $msgs = array_reverse($msgs);
        $users = [];

        $ret = [];
        $lastuser = NULL;
        $lastdate = NULL;

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : null;

        $modaccess = FALSE;

        if ($myid && $myid != $this->chatroom['user1'] && $myid != $this->chatroom['user2']) {
            #error_log("Check mod access $myid, {$this->chatroom['user1']}, {$this->chatroom['user2']}");
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $modaccess = $me->isAdminOrSupport() || $me->moderatorForUser($this->chatroom['user1']) ||
                $me->moderatorForUser($this->chatroom['user2']);
        }

        $lastmsg = NULL;
        $lastref = NULL;

        /** @var ChatMessage $lastm */
        $lastm   = NULL;
        $ctx = NULL;

        foreach ($msgs as $msg) {
            $m = new ChatMessage($this->dbhr, $this->dbhm, $msg['id'], $msg);
            $atts = $m->getPublic($refmsgsummary);
            $atts['bymailid'] = Utils::presdef('bymailid', $msg, NULL);

            $refmsgid = $m->getPrivate('refmsgid');

            if (!$lastmsg || $atts['message'] != $lastmsg || $lastref != $refmsgid) {
                # We can get duplicate messages for a variety of reasons; suppress.
                $lastmsg = $atts['message'];
                $lastref = $refmsgid;

                #error_log("COnsider review {$atts['reviewrequired']}, {$msg['userid']}, $myid, $modaccess");
                if (($atts['reviewrequired'] || ($atts['processingrequired'] && !$atts['processingsuccessful'])) && $msg['userid'] != $myid && !$modaccess) {
                    # This message is held for review, and we didn't send it.  So we shouldn't see it.
                } else if ((!$me || !$me->isAdminOrSupport()) && $atts['reviewrejected']) {
                    # This message was reviewed and deemed unsuitable.  So we shouldn't see it unless we're
                    # an admin or support (where it will be shown struck out).
                } else {
                    # We should return this one.
                    if (!$me || !$me->isAdminOrSupport()) {
                        unset($atts['reviewrequired']);
                        unset($atts['reviewedby']);
                        unset($atts['reviewrejected']);
                        unset($atts['processingrequired']);
                        unset($atts['processingsuccessful']);
                    }

                    $atts['date'] = Utils::ISODate($atts['date']);

                    $atts['sameaslast'] = ($lastuser ==  $msg['userid']);

                    if (count($ret) > 0) {
                        $ret[count($ret) - 1]['sameasnext'] = ($lastuser ==  $msg['userid']);
                        $ret[count($ret) - 1]['gap'] = (strtotime($atts['date']) - strtotime($lastdate)) / 3600 > 1;
                    }

                    if (!array_key_exists($msg['userid'], $users)) {
                        $usettings = Utils::pres('settings', $msg) ? json_decode($msg['settings'], TRUE) : NULL;
                        $profileurl = $msg['userimageurl'] ? $msg['userimageurl'] : ('https://' . IMAGE_DOMAIN . "/uimg_{$msg['userimageid']}.jpg");
                        $profileturl = $msg['userimageurl'] ? $msg['userimageurl'] : ('https://' . IMAGE_DOMAIN . "/tuimg_{$msg['userimageid']}.jpg");
                        $default = FALSE;

                        if (!is_null($usettings) && !Utils::pres('useprofile', $usettings)) {
                            // Should hide image.
                            $profileurl = 'https://' . IMAGE_DOMAIN . '/defaultprofile.png';
                            $profileturl = 'https://' . IMAGE_DOMAIN . '/defaultprofile.png';
                            $default = TRUE;
                        }

                        $users[$msg['userid']] = [
                            'id' => $msg['userid'],
                            'displayname' => User::removeTNGroup($msg['userdisplayname']),
                            'systemrole' => $msg['systemrole'],
                            'profile' => [
                                'url' => $profileurl,
                                'turl' => $profileturl,
                                'default' => $default
                            ]
                        ];
                    }

                    if ($msg['type'] == ChatMessage::TYPE_INTERESTED) {
                        if (!Utils::pres('aboutme', $users[$msg['userid']])) {
                            # Find any "about me" info.
                            $u = User::get($this->dbhr, $this->dbhm, $msg['userid']);
                            $users[$msg['userid']]['aboutme'] = $u->getAboutMe();
                        }
                    }

                    $ret[] = $atts;
                    $lastuser = $msg['userid'];
                    $lastdate = $atts['date'];

                    $ctx['id'] = Utils::pres('id', $ctx) ? min($ctx['id'], $msg['id']) : $msg['id'];
                }
            }

            $lastm = $m;
        }

        return ([$ret, $users]);
    }

    public function lastMailedToAll()
    {
        $sql = "SELECT MAX(id) AS maxid FROM chat_messages WHERE chatid = ? AND mailedtoall = 1;";
        $lasts = $this->dbhr->preQuery($sql, [$this->id]);
        $ret = NULL;

        foreach ($lasts as $last) {
            $ret = $last['maxid'];
        }

        return ($ret);
    }

    public function getMembersStatus($lastmessage, $forceall = FALSE)
    {
        $ret = [];
        #error_log("Get not seen {$this->chatroom['chattype']}");

        if ($this->chatroom['chattype'] == ChatRoom::TYPE_USER2USER) {
            # This is a conversation between two users.  They're both in the roster so we can see what their last
            # seen message was and decide who to chase.  If they've blocked this chat we don't want to see it.
            #
            # Only pluck out the users the chat is between; we might have a roster entry for a mod.
            $readyq = $forceall ? '' : "HAVING lastemailed IS NULL OR lastmsgemailed < ? ";
            $sql = "SELECT chat_roster.* FROM chat_roster 
                 INNER JOIN chat_rooms ON chat_rooms.id = chat_roster.chatid 
                 WHERE chatid = ? AND
                       chat_roster.userid IN (chat_rooms.user1, chat_rooms.user2) AND
                       (status IS NULL OR status != ?) $readyq;";
            #error_log("$sql {$this->id}, $lastmessage");
            $users = $this->dbhr->preQuery($sql, $forceall ? [$this->id, ChatRoom::STATUS_BLOCKED] : [$this->id, ChatRoom::STATUS_BLOCKED, $lastmessage]);

            foreach ($users as $user) {
                # What's the max message this user has either seen or been mailed?
                #error_log("Last mailed to user #{$user['userid']} message {$user['lastmsgemailed']}, last message in chat $lastmessage");
                $maxseen = $forceall ? 0 : Utils::presdef('lastmsgseen', $user, 0);
                $maxmailed = $forceall ? 0 : Utils::presdef('lastmsgemailed', $user, 0);
                $max = max($maxseen, $maxmailed);
                #error_log("Max seen $maxseen mailed $maxmailed max $max VS $lastmessage");

                if ($maxmailed < $lastmessage) {
                    # This user hasn't seen or been mailed all the messages.
                    #error_log("Need to see this");
                    $ret[] = [
                        'userid' => $user['userid'],
                        'lastmsgseen' => $user['lastmsgseen'],
                        'lastmsgemailed' => $user['lastmsgemailed'],
                        'lastmsgseenormailed' => $max,
                        'role' => User::ROLE_MEMBER
                    ];
                }
            }
        } else if ($this->chatroom['chattype'] == ChatRoom::TYPE_USER2MOD) {
            # This is a conversation between a user, and the mods of a group.  We chase the user if they've not
            # seen/been chased, and all the mods if none of them have seen/been chased.
            #
            # First the user.
            $readyq = $forceall ? '' : "HAVING lastemailed IS NULL OR lastmsgemailed < ? ";
            $sql = "SELECT chat_roster.* FROM chat_roster INNER JOIN chat_rooms ON chat_rooms.id = chat_roster.chatid WHERE chatid = ? AND chat_roster.userid = chat_rooms.user1 $readyq;";
            #error_log("Check User2Mod $sql, {$this->id}, $lastmessage");
            $users = $this->dbhr->preQuery($sql, $forceall ? [$this->id] : [$this->id, $lastmessage]);

            foreach ($users as $user) {
                $maxseen = $forceall ? 0 : Utils::presdef('lastmsgseen', $user, 0);
                $maxmailed = $forceall ? 0 : Utils::presdef('lastmsgemailed', $user, 0);
                $max = max($maxseen, $maxmailed);

                #error_log("User in User2Mod max $maxmailed vs $lastmessage");

                if ($maxmailed < $lastmessage) {
                    # We've not been mailed any messages, or some but not this one.
                    $ret[] = [
                        'userid' => $user['userid'],
                        'lastmsgseen' => $user['lastmsgseen'],
                        'lastmsgemailed' => $user['lastmsgemailed'],
                        'lastmsgseenormailed' => $max,
                        'role' => User::ROLE_MEMBER
                    ];
                }
            }

            # Now the mods.
            #
            # First get the mods.
            $mods = $this->dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');", [
                $this->chatroom['groupid']
            ]);

            $modids = [];

            foreach ($mods as $mod) {
                $modids[] = $mod['userid'];
            }

            if (count($modids) > 0) {
                # If for some reason we have no mods, we can't mail them.
                # First add any remaining mods into the roster so that we can record
                # what we do.
                foreach ($mods as $mod) {
                    $sql = "INSERT IGNORE INTO chat_roster (chatid, userid) VALUES (?, ?);";
                    $this->dbhm->preExec($sql, [$this->id, $mod['userid']]);
                }

                # Now return info to trigger mails to all mods.
                $rosters = $this->dbhr->preQuery("SELECT * FROM chat_roster WHERE chatid = ? AND userid IN (" . implode(',', $modids) . ");",
                    [
                        $this->id
                    ]);
                foreach ($rosters as $roster) {
                    $maxseen = $forceall ? 0 : Utils::presdef('lastmsgseen', $roster, 0);
                    $maxmailed = $forceall ? 0 : Utils::presdef('lastemailed', $roster, 0);
                    $max = max($maxseen, $maxmailed);
                    #error_log("Return {$roster['userid']} maxmailed {$roster['lastmsgemailed']} from " . var_export($roster, TRUE));

                    $ret[] = [
                        'userid' => $roster['userid'],
                        'lastmsgseen' => $roster['lastmsgseen'],
                        'lastmsgemailed' => $roster['lastmsgemailed'],
                        'lastmsgseenormailed' => $max,
                        'role' => User::ROLE_MODERATOR
                    ];
                }
            }
        }

        return ($ret);
    }

    private function prepareForTwig($chattype, $notifyingmember, $groupid, $unmailedmsg, $sendingto, $sendingfrom, &$textsummary, $thisone, &$userlist) {
        $u = new User($this->dbhr, $this->dbhm);
        $thistwig = [];
        $profileu = NULL;

        if ($unmailedmsg['type'] != ChatMessage::TYPE_COMPLETED) {
            if ($chattype ==  ChatRoom::TYPE_USER2USER || $chattype ==  ChatRoom::TYPE_MOD2MOD) {
                # Only want to say someone wrote it if they did, which they didn't for system-
                # generated messages.
                if ($unmailedmsg['userid'] == $sendingto->getId()) {
                    $thistwig['mine'] = TRUE;
                    $profileu = $sendingto;

                } else {
                    $thistwig['mine'] = FALSE;
                    $profileu = $sendingfrom;
                }
            } else if ($chattype ==  ChatRoom::TYPE_USER2MOD && $groupid) {
                $g = Group::get($this->dbhr, $this->dbhm, $groupid);

                if ($notifyingmember) {
                    // User2Mod, and we are notifying the member.
                    if ($unmailedmsg['userid'] == $sendingto->getId()) {
                        $thistwig['mine'] = TRUE;
                        $profileu = $sendingto;

                    } else {
                        $thistwig['mine'] = FALSE;
                        $thistwig['profilepic'] = "https://" . IMAGE_DOMAIN . "/gimg_" . $g->getPrivate('profile') . ".jpg";
                    }
                } else {
                    // User2Mod, and we are notifying a mod
                    if ($unmailedmsg['userid'] == $sendingfrom->getId()) {
                        $thistwig['mine'] = TRUE;
                        $profileu = $sendingfrom;

                    } else {
                        $thistwig['mine'] = FALSE;
                        $thistwig['profilepic'] = "https://" . IMAGE_DOMAIN . "/gimg_" . $g->getPrivate('profile') . ".jpg";
                    }
                }
            }

            if ($profileu) {
                if (!array_key_exists($profileu->getId(), $userlist)) {
                    $settings = $profileu->getPrivate('settings');
                    $settings = $settings ? json_decode($settings, TRUE) : [];

                    $users = [ $profileu->getId() => [ 'userid' => $profileu->getId(), 'settings' => $settings ] ];
                    $u->getPublicProfiles($users, []);
                    $userlist[$profileu->getId()] = $users[$profileu->getId()];
                }

                $thistwig['profilepic'] = $userlist[$profileu->getId()]['profile']['turl'];
            }
        }

        if ($unmailedmsg['imageid']) {
            $a = new Attachment($this->dbhr, $this->dbhm, $unmailedmsg['imageid'], Attachment::TYPE_CHAT_MESSAGE);
            $path = $a->getPath(FALSE);
            $thistwig['image'] = $path;
            $textsummary .= "Here's a picture: $path\r\n";
        } else {
            $textsummary .= $thisone . "\r\n";
            $thistwig['message'] = $thisone;
        }

        $thistwig['date'] = date("Y-m-d H:i:s", strtotime($unmailedmsg['date']));
        $thistwig['replyexpected'] = Utils::presdef('replyexpected', $unmailedmsg, FALSE);
        $thistwig['type'] = $unmailedmsg['type'];

        return $thistwig;
    }

    public function getTextSummary($unmailedmsg, $thisu, $otheru, $multiple, &$intsubj) {
        $thisone = NULL;

        switch ($unmailedmsg['type']) {
            case ChatMessage::TYPE_COMPLETED: {
                # There's no text stored for this - we invent it on the client.  Do so here
                # too.
                if ($unmailedmsg['msgtype'] == Message::TYPE_OFFER) {
                    if (Utils::pres('message', $unmailedmsg)) {
                        $thisone = "'{$unmailedmsg['subject']}' is no longer available. \r\n\r\n{$unmailedmsg['message']}";
                    } else {
                        $thisone = "Sorry, '{$unmailedmsg['subject']}' is no longer available. \r\nThis is an automated message.";
                    }
                } else {
                    $thisone = "Thanks, '{$unmailedmsg['subject']}' is no longer needed.";
                }
                break;
            }

            case ChatMessage::TYPE_PROMISED: {
                $thisone = ($unmailedmsg['userid'] == $thisu->getId()) ? ("You promised \"" . $unmailedmsg['subject'] . "\" to " . $otheru->getName()) : ("Good news! " . $otheru->getName() . " has promised \"" . $unmailedmsg['subject'] . "\" to you.");
                break;
            }

            case ChatMessage::TYPE_INTERESTED: {
                $intsubj = "";

                if ($multiple > 1) {
                    # Add in something which identifies the message we're talking about to avoid confusion if this person
                    # is asking about two items.
                    $intsubj = "\"" . $unmailedmsg['subject'] . "\":  ";
                }

                $thisone = $intsubj . $unmailedmsg['message'];
                break;
            }

            case ChatMessage::TYPE_RENEGED: {
                $thisone = ($unmailedmsg['userid'] == $thisu->getId()) ? ("You cancelled your promise to " . $otheru->getName()) : ("Sorry, this is no longer promised to you.");
                break;
            }

            case ChatMessage::TYPE_REPORTEDUSER: {
                $thisone = "This member reported another member with the comment: {$unmailedmsg['message']}";
                break;
            }

            case ChatMessage::TYPE_ADDRESS: {
                # There's no text stored for this - we invent it on the client.  Do so here
                # too.
                $thisone = ($unmailedmsg['userid'] == $thisu->getId()) ? ("You sent an address to " . $otheru->getName() . ".") : ($otheru->getName() . " sent you an address.");
                $thisone .= "\r\n\r\n";
                $addid = intval($unmailedmsg['message']);
                $a = new Address($this->dbhr, $this->dbhm, $addid);

                if ($a->getId()) {
                    $atts = $a->getPublic();

                    if (Utils::pres('multiline', $atts)) {
                        $thisone .= $atts['multiline'];

                        if (Utils::pres('instructions', $atts)) {
                            $thisone .= "\r\n\r\n{$atts['instructions']}";
                        }
                    }
                }

                break;
            }

            case ChatMessage::TYPE_MODMAIL: {
                $thisone = "Message from Volunteers:\r\n\r\n{$unmailedmsg['message']}";
                break;
            }

            case ChatMessage::TYPE_NUDGE: {
                $thisone = ($unmailedmsg['userid'] == $thisu->getId()) ? ("You nudged " . $otheru->getName()) : ("Nudge - please can you reply?");
                break;
            }

            default: {
                # Use the text in the message.
                $thisone = $unmailedmsg['message'];
                break;
            }
        }

        return $thisone;
    }

    public function notifyByEmail($chatid = NULL, $chattype, $emailoverride = NULL, $delay = ChatRoom::DELAY, $since = "4 hours ago", $forceall = FALSE, $sendAndExit = NULL)
    {
        # We want to find chatrooms with messages which haven't been mailed to people.
        #
        # We don't email until a message is older than $delay.  This allows the client to keep messages from
        # being mailed if the user is still typing the next one, so that we will then combine them.
        #
        # These could either be a group chatroom, or a conversation.  There aren't too many of the former, but there
        # could be a large number of the latter.  However we don't want to keep nagging people forever - so we are
        # only interested in rooms containing a message which was posted recently and which has not been mailed all
        # members - which is a much smaller set.
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        # We don't need to check too far back.  This keeps it quick.
        $reviewq = $chattype ==  ChatRoom::TYPE_USER2MOD ? '' : " AND reviewrequired = 0 AND (processingsuccessful = 1 OR processingrequired = 0) ";
        $allq = $forceall ? '' : "AND mailedtoall = 0 AND seenbyall = 0 AND reviewrejected = 0";
        $start = date('Y-m-d H:i:s', strtotime($since));
        $end = date('Y-m-d H:i:s', time() - $delay);
        #error_log("End $end from $delay current " . date('Y-m-d H:i:s'));
        $chatq = $chatid ? " AND chatid = $chatid " : '';
        $sql = "SELECT DISTINCT chatid, chat_rooms.chattype, chat_rooms.groupid, chat_rooms.user1 FROM chat_messages 
    INNER JOIN chat_rooms ON chat_messages.chatid = chat_rooms.id 
    WHERE date >= ? AND date <= ? $allq $reviewq AND chattype = ? $chatq;";
        #error_log("$sql, $start, $end, $chattype");
        $chats = $this->dbhr->preQuery($sql, [$start, $end, $chattype]);
        #error_log("Chats to scan " . count($chats));
        $notified = 0;
        $userlist = [];

        foreach ($chats as $chat) {
            # Different members of the chat might have been mailed different messages.
            #error_log("Check chat {$chat['chatid']}");
            $r = new ChatRoom($this->dbhr, $this->dbhm, $chat['chatid']);
            $chatatts = $r->getPublic();
            $lastmaxmailed = $r->lastMailedToAll();
            $sentsome = FALSE;
            $notmailed = $r->getMembersStatus($chatatts['lastmsg'], $forceall);
            $outcometaken = '';
            $outcomewithdrawn= '';

            #error_log("Notmailed " . count($notmailed) . " with last message {$chatatts['lastmsg']}");

            foreach ($notmailed as $member) {
                # Now we have a member who has not been mailed the messages in this chat.  That's who we're sending to.
                $sendingto = User::get($this->dbhr, $this->dbhm, $member['userid']);
                $other = $member['userid'] == $chatatts['user1']['id'] ? Utils::presdef('id', $chatatts['user2'], NULL) : $chatatts['user1']['id'];
                $sendingfrom = User::get($this->dbhr, $this->dbhm, $other);
                #error_log("Sending to {$sendingto->getEmailPreferred()} from {$sendingfrom->getEmailPreferred()}");

                # For User2Mod chats we do different things based on whether we're notifying the member or the mods.
                $notifyingmember = $chattype ==  ChatRoom::TYPE_USER2MOD && $member['role'] == User::ROLE_MEMBER;

                # We email them if they have mails turned on, and even if they don't have any current memberships.
                # Although that runs the risk of annoying them if they've left, we also have to be able to handle
                # the case where someone replies from a different email which isn't a group membership, and we
                # want to notify that email.
                #
                # If this is a conversation between the user and a mod, we always mail the user.
                #
                # And we always mail TN members, without batching.
                $sendingtoTN = $sendingto->isTN();
                $emailnotifson = $sendingto->notifsOn(User::NOTIFS_EMAIL, $r->getPrivate('groupid'));
                $forcemailfrommod = ($chat['chattype'] ==  ChatRoom::TYPE_USER2MOD && $chat['user1'] ==  $member['userid']);
                $mailson = $emailnotifson || $forcemailfrommod || $sendingtoTN;
                #error_log("Consider mail user {$member['userid']}, mails on " . $sendingto->notifsOn(User::NOTIFS_EMAIL) . ", memberships " . count($sendingto->getMemberships()));
                $sendingown  = $sendingto->notifsOn(User::NOTIFS_EMAIL_MINE);

                # Now collect a summary of what they've missed.  Don't include anything stupid old, in case they
                # have changed settings.
                #
                # For TN members we only want to mail 1 message at a time.
                #
                # For user2mod chats we want to mail messages even if they are held for chat review, because
                # chat review only shows user2user chats, and if we don't do this we could delay chats with mods
                # until the mod next visits the site.
                #
                # Don't mail messages from deleted users.
                $limitq = $sendingtoTN ? " LIMIT 1 " : "";
                $mysqltime = date("Y-m-d", strtotime("Midnight 90 days ago"));
                $readyq = $forceall ? '' : "AND chat_messages.id > ? $reviewq AND reviewrejected = 0 AND chat_messages.date >= ?";
                $ownq = $sendingown ? '' : (" AND chat_messages.userid != " . $sendingto->getId() . " ");
                $sql = "SELECT chat_messages.*, messages.type AS msgtype, messages.subject FROM chat_messages 
    LEFT JOIN messages ON chat_messages.refmsgid = messages.id
    INNER JOIN users ON users.id = chat_messages.userid                                                               
    WHERE chatid = ? AND users.deleted IS NULL $readyq $ownq 
    ORDER BY id ASC $limitq;";
                #error_log("Query $sql");
                $unmailedmsgs = $this->dbhr->preQuery($sql,
                    $forceall ? [ $chat['chatid'] ] :
                    [
                        $chat['chatid'],
                        $member['lastmsgemailed'] ? $member['lastmsgemailed'] : 0,
                        $mysqltime
                    ]);

                #error_log("Unseen by {$sendingto->getId()} {$sendingto->getName()} from {$member['lastmsgemailed']} " . var_export($unmailedmsgs, TRUE));

                if (count($unmailedmsgs) > 0) {
                    $textsummary = '';
                    $twigmessages = [];
                    $lastmsgemailed = 0;
                    $lastmsg = NULL;
                    $justmine = TRUE;
                    $firstid = NULL;
                    $fromname = NULL;
                    $firstmsg = NULL;
                    $refmsgs = [];

                    foreach ($unmailedmsgs as $unmailedmsg) {
                        $this->processUnmailedMessage(
                            $unmailedmsg,
                            $refmsgs,
                            $mailson,
                            $firstid,
                            $sendingto,
                            $sendingfrom,
                            $unmailedmsgs,
                            $intsubj,
                            $outcometaken,
                            $outcomewithdrawn,
                            $justmine,
                            $firstmsg,
                            $lastmsg,
                            $chattype,
                            $notifyingmember,
                            $chat['groupid'],
                            $textsummary,
                            $userlist,
                            $twigmessages,
                            $lastmsgemailed,
                            $fromname,
                            $chatatts['user1']['id']
                        );
                    }

                    #error_log("Consider justmine $justmine TN $sendingtoTN vs " . $sendingto->notifsOn(User::NOTIFS_EMAIL_MINE) . " for " . $sendingto->getId());

                    if (!$justmine || $sendingtoTN || $sendingown) {
                        if (count($twigmessages)) {
                            $groupid = $chat['groupid'];

                            list($subject, $site, $g) = $this->getChatEmailSubject(
                                $chat['chatid'],
                                $groupid,
                                $chattype,
                                $member['role'],
                                $sendingfrom
                            );

                            list($to, $html, $sendname, $notified) = $this->constructTwigMessage(
                                $firstid,
                                $chat['chatid'],
                                $chat['groupid'],
                                $chattype,
                                $notifyingmember,
                                $sendingto,
                                $sendingfrom,
                                $intsubj,
                                $userlist,
                                $site,
                                $member['userid'],
                                $twigmessages,
                                $unmailedmsg['userid'],
                                $twig,
                                $fromname,
                                $outcometaken,
                                $outcomewithdrawn,
                                $justmine,
                                $notified,
                                $lastmsgemailed
                            );

                            if (strlen($html)) {
                                $this->constructSwiftMessageAndSend(
                                    $chat['chatid'],
                                    $member['userid'],
                                    $to,
                                    $subject,
                                    $lastmsgemailed,
                                    $lastmaxmailed,
                                    $chattype,
                                    $r,
                                    $textsummary,
                                    $sendingto,
                                    $sendAndExit,
                                    $emailoverride,
                                    $sendname,
                                    $html,
                                    $sendingfrom,
                                    $member['role'] == User::ROLE_MEMBER ? $groupid : NULL,
                                    $refmsgs,
                                    $justmine,
                                    $sentsome,
                                    $site,
                                    $notified,
                                    TRUE
                                );
                            }
                        }
                    }
                }
            }

            if ($sentsome) {
                # We have now mailed some more.  Note that this is resilient to new messages arriving while we were
                # looping above, because of lastmaxmailed, and we will mail those next time.
                $this->updateMaxMailed($chattype, $chat['chatid'], $lastmaxmailed);

                # Reduce occupancy.
                User::clearCache();
                gc_collect_cycles();
            }
        }

        return ($notified);
    }

    public function updateMaxMailed($chattype, $chatid, $lastmaxmailed) {
        # Find the max message we have mailed to all members of the chat.  Note that this might be less than
        # the max message we just sent.  We might have mailed a message to one user in the chat but not another
        # because we might have thought it was too soon to mail again.  So we need to get it from the roster.
        $mailedtoall = PHP_INT_MAX;
        $maxes = $this->dbhm->preQuery("SELECT lastmsgemailed, userid FROM chat_roster WHERE chatid = ? GROUP BY userid", [
            $chatid
        ]);

        foreach ($maxes as $max) {
            $mailedtoall = min($mailedtoall, $max['lastmsgemailed']);
        }

        $lastmaxmailed = $lastmaxmailed ? $lastmaxmailed : 0;
        #error_log("Set mailedto all for $lastmaxmailed to $maxmailednow for {$chat['chatid']}");
        $this->dbhm->preExec("UPDATE chat_messages SET mailedtoall = 1 WHERE id > ? AND id <= ? AND chatid = ?;", [
            $lastmaxmailed,
            $mailedtoall,
            $chatid
        ]);
    }

    public function splitAndQuote($str) {
        # We want to split the text into lines, without breaking words, and quote them.
        $inlines = preg_split("/(\r\n|\n|\r)/", trim($str));
        $outlines = [];

        foreach ($inlines as $inline) {
            do {
                $inline = trim($inline);

                if (strlen($inline) <= 60) {
                    # Easy.
                    $outlines[] = '> ' . $inline;
                } else {
                    # See if we can find a word break.
                    $p = strrpos(substr($inline, 0, 60), ' ');
                    $splitat = ($p !== FALSE && $p < 60) ? $p : 60;
                    $outlines[] = '> ' . trim(substr($inline, 0, $splitat));
                    $inline = trim(substr($inline, $splitat));

                    if (strlen($inline) && strlen($inline) <= 60) {
                        $outlines[] = '> ' . trim($inline);
                    }
                }
            } while (strlen($inline) > 60);
        }

        return(implode("\r\n", $outlines));
    }

    public function chaseupMods($id = NULL, $age = 566400)
    {
        $notreplied = [];

        # Chase up recent User2Mod chats where there has been no mod input.
        $mysqltime = date("Y-m-d", strtotime("Midnight 2 days ago"));
        $idq = $id ? " AND chat_rooms.id = $id " : '';
        $sql = "SELECT DISTINCT chat_rooms.id FROM chat_rooms INNER JOIN chat_messages ON chat_rooms.id = chat_messages.chatid WHERE chat_messages.date >= '$mysqltime' AND chat_rooms.chattype = 'User2Mod' $idq;";
        $chats = $this->dbhr->preQuery($sql);

        foreach ($chats as $chat) {
            $c = new ChatRoom($this->dbhr, $this->dbhm, $chat['id']);
            list ($msgs, $users) = $c->getMessages();

            # If we have only one user in here then it must tbe the one who started the query.
            if (count($users) == 1) {
                foreach ($users as $uid => $user) {
                    $u = User::get($this->dbhr, $this->dbhm, $uid);
                    $msgs = array_reverse($msgs);
                    $last = $msgs[0];
                    $timeago = strtotime($last['date']);

                    $groupid = $c->getPrivate('groupid');
                    $role = $u->getRoleForGroup($groupid);

                    # Don't chaseup for non-member or mod/owner queries.
                    if ($role == User::ROLE_MEMBER && time() - $timeago >= $age) {
                        $g = new Group($this->dbhr, $this->dbhm, $groupid);

                        if ($g->getPrivate('type') == Group::GROUP_FREEGLE) {
                            error_log("{$chat['id']} on " . $g->getPrivate('nameshort') . " to " . $u->getName() . " (" . $u->getEmailPreferred() . ") last message {$last['date']} total " . count($msgs));

                            if (!array_key_exists($groupid, $notreplied)) {
                                $notreplied[$groupid] = [];

                                # Construct a message.
                                $url = 'https://' . MOD_SITE . '/modtools/chats/' . $chat['id'];
                                $subject = "Member conversation on " . $g->getPrivate('nameshort') . " with " . $u->getName() . " (" . $u->getEmailPreferred() . ")";
                                $fromname = $u->getName();

                                $textsummary = '';
                                $htmlsummary = '';
                                $msgs = array_reverse($msgs);

                                foreach ($msgs as $unseenmsg) {
                                    if (Utils::pres('message', $unseenmsg)) {
                                        $thisone = $unseenmsg['message'];
                                        $textsummary .= $thisone . "\r\n";
                                        $htmlsummary .= nl2br($thisone) . "<br>";
                                    }
                                }

                                $html = chat_chaseup_mod(MOD_SITE, MODLOGO, $fromname, $url, $htmlsummary);

                                # Get the mods.
                                $mods = $g->getMods();

                                foreach ($mods as $modid) {
                                    $thisu = User::get($this->dbhr, $this->dbhm, $modid);
                                    # We ask them to reply to an email address which will direct us back to this chat.
                                    $replyto = 'notify-' . $chat['id'] . '-' . $uid . '@' . USER_DOMAIN;
                                    $to = $thisu->getEmailPreferred();
                                    $message = \Swift_Message::newInstance()
                                        ->setSubject($subject)
                                        ->setFrom([NOREPLY_ADDR => $fromname])
                                        ->setTo([$to => $thisu->getName()])
                                        ->setReplyTo($replyto)
                                        ->setBody($textsummary);

                                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                    # Outlook.
                                    $htmlPart = \Swift_MimePart::newInstance();
                                    $htmlPart->setCharset('utf-8');
                                    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                    $htmlPart->setContentType('text/html');
                                    $htmlPart->setBody($html);
                                    $message->attach($htmlPart);

                                    Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::CHAT_CHASEUP_MODS, $thisu->getId());
                                    $this->mailer($message);
                                }
                            }

                            $notreplied[$groupid][] = $c;
                        }
                    }
                }
            }
        }

        foreach ($notreplied as $groupid => $chatlist) {
            $g = new Group($this->dbhr, $this->dbhm, $groupid);
            error_log("#$groupid " . $g->getPrivate('nameshort') . " " . count($chatlist));
        }

        return ($chats);
    }

    public function delete()
    {
        $rc = $this->dbhm->preExec("DELETE FROM chat_rooms WHERE id = ?;", [$this->id]);
        return ($rc);
    }

    public function replyTime($userid, $force = FALSE) {
        $ret = $this->replyTimes([ $userid ], $force);
        return($ret[$userid]);
    }

    public function replyTimes($uids, $force = FALSE) {
        $times = $this->dbhr->preQuery("SELECT replytime, userid FROM users_replytime WHERE userid IN (" . implode(',', $uids) . ");", NULL, FALSE, FALSE);
        $ret = [];
        $left = $uids;

        foreach ($times as $time) {
            if (!$force && count($times) > 0 && $time['replytime'] < 30*24*60*60) {
                $ret[$time['userid']] = $time['replytime'];

                $left = array_filter($left, function($id) use ($time) {
                    return($id != $time['userid']);
                });
            }
        }

        $left = array_unique($left);

        if (count($left)) {
            $mysqltime = date("Y-m-d", strtotime("90 days ago"));
            $msgs = $this->dbhr->preQuery("SELECT chat_messages.userid, chat_messages.id, chat_messages.chatid, chat_messages.date FROM chat_messages INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid WHERE chat_messages.userid IN (" . implode(',', $left) . ") AND chat_messages.date > ? AND chat_rooms.chattype = ? AND chat_messages.type IN (?, ?);", [
                $mysqltime,
                ChatRoom::TYPE_USER2USER,
                ChatMessage::TYPE_INTERESTED,
                ChatMessage::TYPE_DEFAULT
            ]);

            # Calculate typical reply time.
            foreach ($left as $userid) {
                $delays = [];
                $ret[$userid] = NULL;

                foreach ($msgs as $msg) {
                    if ($msg['userid'] == $userid) {
                        #error_log("$userid Chat message {$msg['id']}, {$msg['date']} in {$msg['chatid']}");
                        # Find the previous message in this conversation.
                        $lasts = $this->dbhr->preQuery("SELECT MAX(date) AS max FROM chat_messages WHERE chatid = ? AND id < ? AND userid != ?;", [
                            $msg['chatid'],
                            $msg['id'],
                            $userid
                        ]);

                        if (count($lasts) > 0 && $lasts[0]['max']) {
                            $thisdelay = strtotime($msg['date']) - strtotime($lasts[0]['max']);;
                            #error_log("Last {$lasts[0]['max']} delay $thisdelay");
                            if ($thisdelay < 30 * 24 * 60 * 60) {
                                # Ignore very large delays - probably dating from a previous interaction.
                                $delays[] = $thisdelay;
                            }
                        }
                    }
                }

                $time = (count($delays) > 0) ? Utils::calculate_median($delays) : NULL;

                # Background these because we've seen occasions where we're in the context of a transaction
                # and this causes a deadlock.
                $timestr = !is_null($time) ? "'$time'" : 'NULL';
                $this->dbhm->background("REPLACE INTO users_replytime (userid, replytime) VALUES ($userid, $timestr);");
                $this->dbhm->background("UPDATE users SET lastupdated = NOW() WHERE id = $userid;");

                $ret[$userid] = $time;
            }
        }

        return($ret);
    }

    public function nudge() {
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);
        $other = $myid == $this->chatroom['user1'] ? $this->chatroom['user2'] : $this->chatroom['user1'];

        # Check that the last message in the chat is not a nudge from us.  That would be annoying.
        $lastmsg = $this->dbhr->preQuery("SELECT id, type, userid FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1;", [
            $this->id
        ]);

        if (count($lastmsg) == 0 || $lastmsg[0]['type'] !== ChatMessage::TYPE_NUDGE || $lastmsg[0]['userid'] != $myid) {
            $m = new ChatMessage($this->dbhr, $this->dbhm);
            $m->create($this->id, $myid, NULL, ChatMessage::TYPE_NUDGE);
            $m->setPrivate('replyexpected', 1);

            # Also record the nudge so that we can see when it has been acted on
            $this->dbhm->preExec("INSERT INTO users_nudges (fromuser, touser) VALUES (?, ?);", [ $myid, $other ]);
            $id = $this->dbhm->lastInsertId();
        } else {
            $id = Utils::presdef('id', $lastmsg, NULL);
        }

        # Create a message in the chat.
        return($id);
    }

    public function typing() {
        # This is invoked by the client when the user is typing, approximately every ten seconds.  We look for any
        # chat messages which are too recent to have mailed out, and bump their time.  This means that if the
        # user keeps typing, we will batch up multiple chat messages in a single email.
        $this->dbhm->preExec("UPDATE chat_messages SET date = NOW() WHERE chatid = ? AND TIMESTAMPDIFF(SECOND, chat_messages.date, NOW()) < ? AND mailedtoall = 0;", [
            $this->id,
            ChatRoom::DELAY
        ]);

        $rc = $this->dbhm->rowsAffected();

        # Record the last typing time.
        $this->dbhm->preExec("UPDATE chat_roster SET lasttype = NOW() WHERE chatid = ? AND userid = ?;", [
            $this->id,
            Session::whoAmId($this->dbhr, $this->dbhm)
        ]);

        return $rc;
    }

    public function sendIt($mailer, $message) {
        $mailer->send($message);
    }

    public function referToSupport() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $message = \Swift_Message::newInstance()
            ->setSubject($me->getName() . " asked for help with chat #{$this->id} " . $this->getName($this->id, $me->getId()))
            ->setFrom([$me->getEmailPreferred()])
            ->setTo(explode(',', SUPPORT_ADDR))
            ->setBody('Please review the chat at https://' . MOD_SITE . "/modtools/support/refer/{$this->id} and then reply to this email to contact the mod who requested help.");

        list ($transport, $mailer) = Mail::getMailer();

        Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::REFER_TO_SUPPORT);

        $this->sendIt($mailer, $message);
    }

    public function nudgess($uids) {
        return($this->dbhr->preQuery("SELECT * FROM users_nudges WHERE touser IN (" . implode(',', $uids) . ");", NULL, FALSE, FALSE));
    }

    public function nudges($userid) {
        return($this->nudgess([ $userid ]));
    }

    public function nudgeCount($userid) {
        return($this->nudgeCounts([ $userid ])[$userid]);
    }

    public function nudgeCounts($uids) {
        $nudges = $this->nudgess($uids);
        $ret = [];

        foreach ($uids as $uid) {
            $sent = 0;
            $responded = 0;

            foreach ($nudges as $nudge) {
                $sent++;
                $responded = $nudge['responded'] ? ($responded + 1) : $responded;
            }

            $ret[$uid] = [
                'sent' => $sent,
                'responded' => $responded
            ];
        }

        return $ret;
    }
    
    public function updateExpected() {
        $oldest = date("Y-m-d", strtotime("Midnight 31 days ago"));
        $expecteds = $this->dbhr->preQuery("SELECT chat_messages.*, user1, user2 FROM chat_messages INNER JOIN chat_rooms ON chat_messages.chatid = chat_rooms.id WHERE chat_messages.date>= '$oldest' AND replyexpected = 1 AND replyreceived = 0 AND chat_rooms.chattype = 'User2User';");
        $received = 0;
        $waiting = 0;

        foreach ($expecteds as $expected) {
            $afters = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE chatid = ? AND id > ? AND userid != ?;",
                                      [
                                          $expected['chatid'],
                                          $expected['id'],
                                          $expected['userid']
                                      ]);

            $count = $afters[0]['count'];
            $other = $expected['userid'] == $expected['user1'] ? $expected['user2'] : $expected['user1'];

            # There's a timing window where a merge can cause the other user id to be invalid, so we use IGNORE.
            if ($count) {
                #error_log("Expected received to {$expected['date']} {$expected['id']} from user #{$expected['userid']}");
                $this->dbhm->preExec("UPDATE chat_messages SET replyreceived = 1 WHERE id = ?;", [
                    $expected['id']
                ]);

                $this->dbhm->preExec("INSERT IGNORE INTO users_expected (expecter, expectee, chatmsgid, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = ?;", [
                    $expected['userid'],
                    $other,
                    $expected['id'],
                    1,
                    1
                ]);

                $received++;
            } else {
                $this->dbhm->preExec("INSERT IGNORE INTO users_expected (expecter, expectee, chatmsgid, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = ?;", [
                    $expected['userid'],
                    $other,
                    $expected['id'],
                    -1,
                    -1
                ]);

                $waiting++;
            }
        }

        return [ $waiting, $received ];
    }

    private function recordSend( $lastmsgemailed, $userid, $chatid1): void
    {
        $this->dbhm->preExec(
            "UPDATE chat_roster SET lastemailed = NOW(), lastmsgemailed = ? WHERE userid = ? AND chatid = ?;",
            [
                $lastmsgemailed,
                $userid,
                $chatid1
            ]
        );
    }

    public function chaseupExpected() {
        $chased = 0;

        $oldest = date("Y-m-d", strtotime("Midnight " . ChatRoom::EXPECTED_CHASEUP . " days ago"));
        $unmailedmsgs = $this->dbhr->preQuery("SELECT chat_messages.*, chat_rooms.chattype, messages.type AS msgtype, messages.subject, expectee FROM users_expected
            INNER JOIN users ON users.id = users_expected.expectee
            INNER JOIN chat_messages ON chat_messages.id = users_expected.chatmsgid
            INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid
            LEFT JOIN messages ON chat_messages.refmsgid = messages.id
            LEFT JOIN chat_roster ON chat_roster.userid = expectee AND chat_roster.chatid = chat_messages.chatid
            WHERE chat_messages.date >= ? AND
                replyexpected = 1 AND
                replyreceived = 0 AND
                chat_roster.status != ? AND
                TIMESTAMPDIFF(MINUTE, chat_messages.date, users.lastaccess) >= ? AND
                chat_rooms.chattype = ?
            GROUP BY expectee, chatid;", [
                        $oldest,
                        ChatRoom::STATUS_BLOCKED,
                        ChatRoom::EXPECTED_GRACE,
                        ChatRoom::TYPE_USER2USER
                    ]);

        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        $userlist = [];

        foreach ($unmailedmsgs as $unmailedmsg) {
            $textsummary = '';
            $twigmessages = [];
            $lastmsgemailed = 0;
            $lastmsg = NULL;
            $justmine = TRUE;
            $firstid = NULL;
            $fromname = NULL;
            $firstmsg = NULL;
            $refmsgs = [];

            $sendingto = User::get($this->dbhr, $this->dbhm, $unmailedmsg['expectee']);
            $sendingtoTN = $sendingto->isTN();
            $emailnotifson = $sendingto->notifsOn(User::NOTIFS_EMAIL);
            $mailson = $emailnotifson || $sendingtoTN;

            $r = new ChatRoom($this->dbhr, $this->dbhm, $unmailedmsg['chatid']);
            $chatatts = $r->getPublic();
            $lastmaxmailed = $r->lastMailedToAll();
            $other = $unmailedmsg['expectee'] == $chatatts['user1']['id'] ? Utils::presdef('id', $chatatts['user2'], NULL) : $chatatts['user1']['id'];
            $sendingfrom = User::get($this->dbhr, $this->dbhm, $other);

            $this->processUnmailedMessage(
                $unmailedmsg,
                $refmsgs,
                $mailson,
                $firstid,
                $sendingto,
                $sendingfrom,
                $unmailedmsgs,
                $intsubj,
                $outcometaken,
                $outcomewithdrawn,
                $justmine,
                $firstmsg,
                $lastmsg,
                ChatRoom::TYPE_USER2USER,
                FALSE,
                NULL,
                $textsummary,
                $userlist,
                $twigmessages,
                $lastmsgemailed,
                $fromname,
                $chatatts['user1']['id']
            );

            if (!$justmine || $sendingtoTN) {
                if (count($twigmessages)) {
                    list($subject, $site) = $this->getChatEmailSubject(
                        $unmailedmsg['chatid'],
                        NULL,
                        ChatRoom::TYPE_USER2USER,
                        User::ROLE_MEMBER,
                        $sendingfrom
                    );

                    $subject = 'WAITING FOR REPLY: ' . $subject;

                    list($to, $html, $sendname, $notified) = $this->constructTwigMessage(
                        $firstid,
                        $unmailedmsg['chatid'],
                        NULL,
                        ChatRoom::TYPE_USER2USER,
                        FALSE,
                        $sendingto,
                        $sendingfrom,
                        $intsubj,
                        $userlist,
                        $site,
                        $unmailedmsg['expectee'],
                        $twigmessages,
                        $unmailedmsg['userid'],
                        $twig,
                        $fromname,
                        $outcometaken,
                        $outcomewithdrawn,
                        $justmine,
                        $notified,
                        $lastmsgemailed,
                    );

                    if (strlen($html)) {
                        $this->constructSwiftMessageAndSend(
                            $unmailedmsg['chatid'],
                            $unmailedmsg['expectee'],
                            $to,
                            $subject,
                            $lastmsgemailed,
                            $lastmaxmailed,
                            ChatRoom::TYPE_USER2USER,
                            $r,
                            $textsummary,
                            $sendingto,
                            FALSE,
                            NULL,
                            $sendname,
                            $html,
                            $sendingfrom,
                            NULL,
                            $refmsgs,
                            $justmine,
                            $sentsome,
                            $site,
                            $notified,
                            FALSE
                        );

                        $chased++;
                    }
                }
            }
        }

        return $chased;
    }

    private function processUnmailedMessage(
        &$unmailedmsg,
        &$refmsgs,
        $mailson,
        &$firstid,
        $sendingto,
        $sendingfrom,
        $unmailedmsgs,
        &$intsubj,
        &$outcometaken,
        &$outcomewithdrawn,
        &$justmine,
        &$lastmsg,
        &$firstmsg,
        $chattype,
        $notifyingmember,
        $groupid1,
        &$textsummary,
        &$userlist,
        &$twigmessages,
        &$lastmsgemailed,
        &$fromname,
        $id
    ) {
        if (Utils::pres('refmsgid', $unmailedmsg)) {
            $refmsgs[] = $unmailedmsg['refmsgid'];
        }

        # Message might be empty.
        $unmailedmsg['message'] = strlen(
            trim($unmailedmsg['message'])
        ) === 0 ? "(Empty message)" : $unmailedmsg['message'];

        # Exclamation marks make emails look spammy, in conjunction with 'free' (which we use because,
        # y'know, freegle) according to Litmus.  Remove them.
        $unmailedmsg['message'] = str_replace('!', '.', $unmailedmsg['message']);

        # Convert all emojis to smilies.  Obviously that's not right, but most of them are, and we want
        # to get rid of the unicode.
        $unmailedmsg['message'] = preg_replace('/\\\\u.*?\\\\u/', ':-)', $unmailedmsg['message']);

        if ($mailson) {
            if (!$firstid) {
                # We're going to want to include the previous message as reply context, so we need
                # to know the id of the first message we're sending.
                $firstid = $unmailedmsg['id'];
            }

            $thisone = $this->getTextSummary(
                $unmailedmsg,
                $sendingto,
                $sendingfrom,
                count($unmailedmsgs) > 1,
                $intsubj
            );

            switch ($unmailedmsg['type']) {
                case ChatMessage::TYPE_INTERESTED: {
                    if ($unmailedmsg['refmsgid'] && $unmailedmsg['msgtype'] == Message::TYPE_OFFER) {
                        # We want to add in taken/received/withdrawn buttons.
                        $outcometaken = $sendingfrom->loginLink(
                            USER_SITE,
                            $sendingfrom->getId(),
                            "/mypost/{$unmailedmsg['refmsgid']}/completed",
                            User::SRC_CHATNOTIF
                        );
                        $outcomewithdrawn = $sendingfrom->loginLink(
                            USER_SITE,
                            $sendingfrom->getId(),
                            "/mypost/{$unmailedmsg['refmsgid']}/withdraw",
                            User::SRC_CHATNOTIF
                        );
                    }
                    break;
                }
            }

            # Have we got any messages from someone else?
            $justmine = ($unmailedmsg['userid'] != $sendingto->getId()) ? false : $justmine;
            #error_log("From {$unmailedmsg['userid']} $thisone justmine? $justmine");

            if (!$lastmsg || $lastmsg != $thisone) {
                $twigmessages[] = $this->prepareForTwig(
                    $chattype,
                    $notifyingmember,
                    $groupid1,
                    $unmailedmsg,
                    $sendingto,
                    $sendingfrom,
                    $textsummary,
                    $thisone,
                    $userlist
                );

                $lastmsgemailed = max($lastmsgemailed, $unmailedmsg['id']);
                $lastmsg = $thisone;
            }
        }

        # We want to include the name of the last person sending a message.
        switch ($chattype) {
            case ChatRoom::TYPE_USER2USER:
                # We might be sending a copy of the user's own message, so the fromname could be either.
                $fromname = $unmailedmsg['userid'] == $sendingto->getId() ?
                    $sendingto->getName() :
                    $sendingfrom->getName();
                break;
            case ChatRoom::TYPE_USER2MOD:
                if ($notifyingmember) {
                    # Always show message from volunteers.
                    $g = Group::get($this->dbhr, $this->dbhm, $groupid1);
                    $fromname = $g->getPublic()['namedisplay'] . " volunteers";
                } else {
                    if ($unmailedmsg['userid'] == $id) {
                        # Notifying mod of message from member.
                        $u = User::get($this->dbhr, $this->dbhm, $unmailedmsg['userid']);
                        $fromname = $u->getName();
                    } else {
                        # Notifying mod of message from another mod.
                        $g = Group::get($this->dbhr, $this->dbhm, $groupid1);
                        $fromname = $g->getPublic()['namedisplay'] . " volunteers";
                    }
                }
                break;
            case ChatRoom::TYPE_MOD2MOD:
                # Notifying mod of message from another mod, but can can show who.
                $u = User::get($this->dbhr, $this->dbhm, $unmailedmsg['userid']);
                $fromname = $u->getName();
                break;
        }
    }

    private function getChatEmailSubject($chatid, $groupid, $chattype, $role, $sendingfrom) {
        # As a subject, we should use the last "interested in" message in this chat - this is the
        # most likely thing they are talking about.
        $sql = "SELECT subject, nameshort, namefull FROM messages INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id INNER JOIN `groups` ON groups.id = messages_groups.groupid WHERE chatid = ? AND chat_messages.type = ? ORDER BY chat_messages.id DESC LIMIT 1;";
        #error_log($sql . $chat['chatid']);
        $subjs = $this->dbhr->preQuery($sql, [
            $chatid,
            ChatMessage::TYPE_INTERESTED
        ]);
        #error_log(var_export($subjs, TRUE));

        switch ($chattype) {
            case ChatRoom::TYPE_USER2USER:
                if (count($subjs)) {
                    $groupname = Utils::presdef('namefull', $subjs[0], $subjs[0]['nameshort']);
                    $subject = count($subjs) == 0 ?: ("Regarding: [$groupname] " .
                        str_replace('Regarding:', '', str_replace('Re: ', '', $subjs[0]['subject'])));
                } else {
                    $subject = "[Freegle] You have a new message";
                }
                $site = USER_SITE;
                break;
            case ChatRoom::TYPE_USER2MOD:
                # We might either be notifying a user, or the mods.
                $g = Group::get($this->dbhr, $this->dbhm, $groupid);

                if ($role == User::ROLE_MEMBER) {
                    $subject = "Your conversation with the " . $g->getPublic()['namedisplay'] . " volunteers";
                    $site = USER_SITE;
                } else {
                    $subject = "Member conversation on " . $g->getPrivate(
                            'nameshort'
                        ) . " with " . $sendingfrom->getName() . " (" . $sendingfrom->getEmailPreferred() . ")";
                    $site = MOD_SITE;
                }
                break;
        }

        return [ $subject, $site ];
    }

    private function constructTwigMessage(
        $firstid,
        $chatid,
        $groupid,
        $chattype,
        $notifyingmember,
        $sendingto,
        $sendingfrom,
        &$intsubj,
        &$userlist,
        $site,
        $memberuserid,
        $twigmessages,
        $unmaileduserid,
        $twig,
        $fromname,
        $outcometaken,
        $outcomewithdrawn,
        $justmine,
        $notified,
        $lastmsgemailed,
    ) {
        # Construct the SMTP message.
        # - The text bodypart is just the user text.  This means that people who aren't showing HTML won't see
        #   all the wrapping.  It also means that the kinds of preview notification popups you get on mail
        #   clients will show something interesting.
        # - The HTML bodypart will show the user text, but in a way that is designed to encourage people to
        #   click and reply on the web rather than by email.  This reduces the problems we have with quoting,
        #   and encourages people to use the (better) web interface, while still allowing email replies for
        #   those users who prefer it.  Because we put the text they're replying to inside a visual wrapping,
        #   it's less likely that they will interleave their response inside it - they will probably reply at
        #   the top or end.  This makes it easier for us, when processing their replies, to spot the text they
        #   added.
        #
        # In both cases we include the previous message quoted to look like an old-school email reply.  This
        # provides some context, and may also help with spam filters by avoiding really short messages.
        $prevmsg = [];

        if ($firstid) {
            # Get the last few substantive message in the chat before this one, if any are recent.
            $earliest = date("Y-m-d", strtotime("Midnight 90 days ago"));
            $prevmsgs = $this->dbhr->preQuery(
                "SELECT chat_messages.*, messages.type AS msgtype, messages.subject FROM chat_messages LEFT JOIN messages ON chat_messages.refmsgid = messages.id WHERE chatid = ? AND chat_messages.id < ? AND chat_messages.date >= '$earliest' ORDER BY chat_messages.id DESC LIMIT 3;",
                [
                    $chatid,
                    $firstid
                ]
            );

            $prevmsgs = array_reverse($prevmsgs);

            $bin = '';

            foreach ($prevmsgs as $p) {
                $prevmsg[] = $this->prepareForTwig(
                    $chattype,
                    $notifyingmember,
                    $groupid,
                    $p,
                    $sendingto,
                    $sendingfrom,
                    $bin,
                    $this->getTextSummary($p, $sendingto, $sendingfrom, count($prevmsgs) > 1, $intsubj),
                    $userlist
                );
            }
        }

        $url = $sendingto->loginLink($site, $memberuserid, '/chats/' . $chatid, User::SRC_CHATNOTIF);
        $to = $sendingto->getEmailPreferred();

        #$to = 'log@ehibbert.org.uk';

        $jobads = $sendingto->getJobAds();

        $replyexpected = false;
        foreach ($twigmessages as $t) {
            if (Utils::presbool('replyexpected', $t, false))
            {
                $replyexpected = true;
            }
        }

        $html = '';

        try {
            switch ($chattype) {
                case ChatRoom::TYPE_USER2USER:
                    if (!$sendingto->isLJ()) {
                        # We might be sending a copy of the user's own message
                        $aboutme = $unmaileduserid == $sendingto->getId() ?
                            $sendingto->getAboutMe() :
                            $sendingfrom->getAboutMe();

                        $html = $twig->render('chat_notify.html', [
                            'unsubscribe' => $sendingto->getUnsubLink($site, $memberuserid, User::SRC_CHATNOTIF),
                            'fromname' => $fromname ? $fromname : $sendingfrom->getName(),
                            'fromid' => $sendingfrom->getId(),
                            'reply' => $url,
                            'messages' => $twigmessages,
                            'backcolour' => '#FFF8DC',
                            'email' => $to,
                            'aboutme' => $aboutme ? $aboutme['text'] : '',
                            'previousmessages' => $prevmsg,
                            'jobads' => $jobads['jobs'],
                            'joblocation' => $jobads['location'],
                            'outcometaken' => $outcometaken,
                            'outcomewithdrawn' => $outcomewithdrawn,
                            'replyexpected' => $replyexpected
                        ]);

                        $sendname = $justmine ? $sendingto->getName() : $sendingfrom->getName();
                    } else {
                        // LoveJunk user.  We send via their API.
                        $l = new LoveJunk($this->dbhr, $this->dbhm);
                        $msg = "";

                        foreach ($twigmessages as $t) {
                            if ($t['type'] == ChatMessage::TYPE_PROMISED) {
                                # This is a separate API call.  It's possible that there are
                                # chat messages too, and therefore we might promise slightly out
                                # of sequence.  But that is kind of OK, as the user might have done
                                # that.
                                $notified += $l->promise($chatid);
                            } elseif ($t['type'] == ChatMessage::TYPE_RENEGED) {
                                # Ditto.
                                $notified += $l->renege($chatid);
                            } else {
                                # Use the text summary.
                                $tt = trim($t['message']);

                                if ($tt) {
                                    $msg .= "$tt\n";
                                }
                            }
                        }

                        if (strlen($msg)) {
                            $notified += $l->sendChatMessage($chatid, $msg);
                        }

                        // Don't try to send by email below.
                        $this->recordSend($lastmsgemailed, $memberuserid, $chatid);
                        $html = '';
                    }
                    break;
                case ChatRoom::TYPE_USER2MOD:
                    $g = Group::get($this->dbhr, $this->dbhm, $groupid);

                    if ($notifyingmember) {
                        $html = $twig->render('chat_notify.html', [
                            'unsubscribe' => $sendingto->getUnsubLink($site, $memberuserid, User::SRC_CHATNOTIF),
                            'fromname' => $fromname ? $fromname : ($g->getName() . ' volunteers'),
                            'reply' => $url,
                            'messages' => $twigmessages,
                            'backcolour' => '#FFF8DC',
                            'email' => $to,
                            'previousmessages' => $prevmsg,
                            'jobads' => $jobads['jobs'],
                            'joblocation' => $jobads['location'],
                            'outcometaken' => $outcometaken,
                            'outcomewithdrawn' => $outcomewithdrawn,
                        ]);

                        $sendname = $g->getName() . ' volunteers';
                    } else {
                        $url = $sendingto->loginLink(
                            $site,
                            $memberuserid,
                            '/modtools/chats/' . $chatid,
                            User::SRC_CHATNOTIF
                        );
                        $html = $twig->render('chat_notify.html', [
                            'unsubscribe' => $sendingto->getUnsubLink($site, $memberuserid, User::SRC_CHATNOTIF),
                            'fromname' => $fromname ? $fromname : $sendingfrom->getName(),
                            'fromid' => $sendingfrom->getId(),
                            'reply' => $url,
                            'messages' => $twigmessages,
                            'ismod' => $sendingto->isModerator(),
                            'support' => SUPPORT_ADDR,
                            'backcolour' => '#FFF8DC',
                            'email' => $to,
                            'previousmessages' => $prevmsg,
                            'jobads' => $jobads['jobs'],
                            'joblocation' => $jobads['location'],
                            'outcometaken' => $outcometaken,
                            'outcomewithdrawn' => $outcomewithdrawn,

                        ]);

                        $sendname = 'Reply All';
                    }
                    break;
            }
        } catch (\Exception $e) {
            $html = '';
            error_log("Twig failed with " . $e->getMessage());
        }

        return [ $to, $html, $sendname, $notified ];
    }

    private function constructSwiftMessageAndSend(
        $chatid1,
        $sendtoid,
        $to,
        $subject,
        $lastmsgemailed,
        $lastmaxmailed,
        $chattype,
        $r,
        &$textsummary,
        $sendingto,
        $sendAndExit,
        $emailoverride,
        $sendname,
        $html,
        $sendingfrom,
        $groupid,
        $refmsgs,
        $justmine,
        &$sentsome,
        $site,
        &$notified,
        $recordsend
    ) {
        # We ask them to reply to an email address which will direct us back to this chat.
        #
        # Use a special user for yahoo.co.uk to work around deliverability issues.
        $domain = USER_DOMAIN;
        #$domain = 'users2.ilovefreegle.org';
        $replyto = 'notify-' . $chatid1 . '-' . $sendtoid . '@' . $domain;

        # ModTools users should never get notified.
        if ($to && strpos($to, MOD_SITE) === false) {
            error_log(
                "Notify chat #{$chatid1} $to for {$sendtoid} $subject last mailed will be $lastmsgemailed lastmax $lastmaxmailed"
            );

            # Firewall against a case we have seen during cluster issues, which we don't understand
            # but which led to us sending chats to the wrong user.
            if ($chattype == ChatRoom::TYPE_USER2USER &&
                ($r->getPrivate('id') != $chatid1 ||
                    ($sendtoid != $r->getPrivate('user1') && $sendtoid != $r->getPrivate('user2')))) {
                $errormsg = "Chat inconsistency - cluster issue? Chat {$r->getPrivate('id')} vs {$chatid1} user {$sendtoid} vs {$r->getPrivate('user1')} and {$r->getPrivate('user2')}";
                error_log($errormsg);
                \Sentry\captureMessage($errormsg);
                exit(1);
            }

            try {
                #error_log("Our email " . $sendingto->getOurEmail() . " for " . $sendingto->getEmailPreferred());
                # Make the text summary longer, because this helps with spam detection according
                # to Litmus.
                $textsummary .= "\r\n\r\n-------\r\nThis is a text-only version of the message; you can also view this message in HTML if you have it turned on, and on the website.  We're adding this because short text messages don't always get delivered successfully.\r\n";
                $message = $this->constructSwiftMessage(
                    $sendtoid,
                    $sendingto->getName(),
                    $sendAndExit ? $sendAndExit : ($emailoverride ? $emailoverride : $to),
                    $sendname . ' on ' . SITE_NAME,
                    $replyto,
                    $subject,
                    $textsummary,
                    $html,
                    $chattype == ChatRoom::TYPE_USER2USER ? $sendingfrom->getId() : null,
                    $groupid,
                    $refmsgs
                );

                if ($message) {
                    if ($chattype == ChatRoom::TYPE_USER2USER && $sendingto->getId() && !$justmine) {
                        # Request read receipt.  We will often not get these for privacy reasons, but if
                        # we do, it's useful to have to that we can display feedback to the sender.
                        $headers = $message->getHeaders();
                        $headers->addTextHeader(
                            'Disposition-Notification-To',
                            "readreceipt-{$chatid1}-{$sendtoid}-$lastmsgemailed@" . USER_DOMAIN
                        );
                        $headers->addTextHeader(
                            'Return-Receipt-To',
                            "readreceipt-{$chatid1}-{$sendtoid}-$lastmsgemailed@" . USER_DOMAIN
                        );
                    }

                    $this->mailer($message, $chattype == ChatRoom::TYPE_USER2USER ? $to : null);

                    if ($sendAndExit) {
                        error_log("Sent to $sendAndExit, exiting...");
                        exit(0);
                    }

                    $sentsome = true;

                    if ($recordsend) {
                        $this->recordSend($lastmsgemailed, $sendtoid, $chatid1);
                    }

                    if ($chattype == ChatRoom::TYPE_USER2USER && !$justmine) {
                        # Send any SMS, but not if we're only mailing our own messages
                        $smsmsg = ($textsummary && substr($textsummary, 0, 1) != "\r") ? ('New message: "' . substr(
                                $textsummary,
                                0,
                                30
                            ) . '"...') : 'You have a new message.';
                        $sendingto->sms($smsmsg, 'https://' . $site . '/chats/' . $chatid1 . '?src=sms');
                    }

                    $notified++;
                }
            } catch (\Exception $e) {
                error_log("Send to {$sendtoid} failed with " . $e->getMessage());
            }
        }

        return array($textsummary, $sentsome, $notified);
    }
}
