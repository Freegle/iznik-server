<?php
namespace Freegle\Iznik;

use Pheanstalk\Pheanstalk;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/mailtemplates/chat_chaseup_mod.php');

class ChatRoom extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'name', 'chattype', 'groupid', 'description', 'user1', 'user2', 'synctofacebook');
    var $settableatts = array('name', 'description');

    const ACTIVELIM = "31 days ago";
    const TYPE_MOD2MOD = 'Mod2Mod';
    const TYPE_USER2MOD = 'User2Mod';
    const TYPE_USER2USER = 'User2User';
    const TYPE_GROUP = 'Group';

    const STATUS_ONLINE = 'Online';
    const STATUS_OFFLINE = 'Offline';
    const STATUS_AWAY = 'Away';
    const STATUS_CLOSED = 'Closed';
    const STATUS_BLOCKED = 'Blocked';

    const CACHED_LIST_SIZE = 20;

    # States for syncing chats to Facebook.
    const FACEBOOK_SYNC_DONT = 'Dont';                                      # Default - don't sync
    const FACEBOOK_SYNC_REPLIED_ON_FACEBOOK = 'RepliedOnFacebook';          # We've had the initial reply from FB
    const FACEBOOK_SYNC_REPLIED_ON_PLATFORM = 'RepliedOnPlatform';          # We've replied on our platform
    const FACEBOOK_SYNC_POSTED_LINK = 'PostedLink';                         # We've posted a link to this chat on FB.

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

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

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
        $sql = "
SELECT chat_rooms.*, CASE WHEN namefull IS NOT NULL THEN namefull ELSE nameshort END AS groupname, 
CASE WHEN u1.fullname IS NOT NULL THEN u1.fullname ELSE CONCAT(u1.firstname, ' ', u1.lastname) END AS u1name,
CASE WHEN u2.fullname IS NOT NULL THEN u2.fullname ELSE CONCAT(u2.firstname, ' ', u2.lastname) END AS u2name,
u1.settings AS u1settings,
u2.settings AS u2settings,
(SELECT COUNT(*) AS count FROM chat_messages WHERE id > 
  COALESCE((SELECT lastmsgseen FROM chat_roster WHERE chatid = chat_rooms.id AND userid = ? AND status != ? AND status != ?), 0) 
  AND chatid = chat_rooms.id AND userid != ? AND reviewrequired = 0 AND reviewrejected = 0) AS unseen,
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
END AS lastmsgseen" : '') . "     
FROM chat_rooms LEFT JOIN groups ON groups.id = chat_rooms.groupid 
LEFT JOIN users u1 ON chat_rooms.user1 = u1.id
LEFT JOIN users u2 ON chat_rooms.user2 = u2.id 
LEFT JOIN users_images i1 ON i1.userid = u1.id
LEFT JOIN users_images i2 ON i2.userid = u2.id
LEFT JOIN groups_images i3 ON i3.groupid = chat_rooms.groupid 
LEFT JOIN chat_messages ON chat_messages.id = (SELECT id FROM chat_messages WHERE chat_messages.chatid = chat_rooms.id AND reviewrequired = 0 AND reviewrejected = 0 ORDER BY chat_messages.id DESC LIMIT 1)
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

        foreach ($rooms as &$room) {
            $room['u1name'] = presdef('u1name', $room, 'Someone');
            $room['u2name'] = presdef('u2name', $room, 'Someone');

            if (pres('lastdate', $room)) {
                $room['lastdate'] = ISODate($room['lastdate']);
                $room['latestmessage'] = ISODate($room['latestmessage']);
            }

            if (!MODTOOLS) {
                # We might be forbidden from showing the profiles.
                $u1settings = pres('u1settings', $room) ? json_decode($room['u1settings'], TRUE) : NULL;
                $u2settings = pres('u2settings', $room) ? json_decode($room['u2settings'], TRUE) : NULL;

                if ($u1settings !== NULL && !pres('useprofile', $u1settings)) {
                    $room['u1defaultimage'] = TRUE;
                }

                if ($u2settings !== NULL && !pres('useprofile', $u2settings)) {
                    $room['u2defaultimage'] = TRUE;
                }
            }

            switch ($room['chattype']) {
                case ChatRoom::TYPE_USER2USER:
                    # We use the name of the user who isn't us, because that's who we're chatting to.
                    $room['name'] = $myid == $room['user1'] ? $room['u2name'] : $room['u1name'];
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
                    'lastdate' => presdef('lastdate', $room, NULL),
                    'lastmsg' => presdef('lastmsg', $room, NULL),
                    'synctofacebook' => $room['synctofacebook'],
                    'unseen' => $room['unseen'],
                    'name' => $room['name'],
                    'id' => $room['id'],
                    'replyexpected' => $room['replyexpected']
                ];
                
                $thisone['snippet'] = $this->getSnippet($room['chatmsgtype'], $room['chatmsg']);

                switch ($room['chattype']) {
                    case ChatRoom::TYPE_USER2USER:
                        if ($room['user1'] == $myid) {
                            $thisone['icon'] = pres('u2defaultimage', $room) ? ('https://' . USER_DOMAIN . '/defaultprofile.png') : ('https://' . IMAGE_DOMAIN . "/tuimg_" . $room['u2imageid']  . ".jpg");
                        } else {
                            $thisone['icon'] = pres('u1defaultimage', $room) ? ('https://' . USER_DOMAIN . '/defaultprofile.png') : ('https://' . IMAGE_DOMAIN . "/tuimg_" . $room['u1imageid'] . ".jpg");
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
                    if ($chat['id'] === $id['chatid']) {
                        if (pres('refmsgids', $chat)) {
                            $chat['refmsgids'][] = $id['refmsgid'];
                        } else {
                            $chat['refmsgids'] = [ $id['refmsgid'] ];
                        }
                    }
                }
            }
        }

        return($ret);
    }

    # This can be overridden in UT.
    public function constructMessage(User $u, $id, $toname, $to, $fromname, $from, $subject, $text, $html, $fromuid = NULL, $groupid = NULL)
    {
        $_SERVER['SERVER_NAME'] = USER_DOMAIN;
        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom([$from => $fromname])
#            ->setBcc('log@ehibbert.org.uk')
            ->setBody($text);

        try {
            $message->setTo([$to => $toname]);

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

            $headers = $message->getHeaders();

            $headers->addTextHeader('List-Unsubscribe', $u->listUnsubscribe(USER_SITE, $id, User::SRC_CHATNOTIF));

            if ($fromuid) {
                $headers->addTextHeader('X-Freegle-From-UID', $fromuid);
            }

            if ($groupid) {
                $headers->addTextHeader('X-Freegle-Group-Volunteer', $groupid);
            }
        } catch (\Exception $e) {
            # Flag the email as bouncing.
            error_log("Email $to for member #$id invalid, flag as bouncing");
            $this->dbhm->preExec("UPDATE users SET bouncing = 1 WHERE id = ?;", [  $id ]);
            $message = NULL;
        }

        return ($message);
    }

    public function mailer($message, $recip = NULL)
    {
        list ($transport, $mailer) = getMailer();

        if (RETURN_PATH && Mail::shouldSend(Mail::CHAT)) {
            # Also send this to the seed list so that we can measure inbox placement.
            #
            # We send this as a BCC because this plays nicer with Litmus
            $seeds = Mail::getSeeds($this->dbhr, $this->dbhm);

            $bcc = [];

            foreach ($seeds as $seed) {
                $u = User::get($this->dbhr, $this->dbhm, $seed['userid']);
                $bcc[] = $u->getEmailPreferred();
            }

            $message->setBcc($bcc);
        }

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
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = $me ? $me->getId() : NULL;

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

            $user1groups = $this->dbhr->preQuery("SELECT groupid FROM memberships WHERE userid = ?;", [
                $user1
            ]);

            $user2groups = $this->dbhr->preQuery("SELECT groupid FROM memberships WHERE userid = ?;", [
                $user2
            ]);

            $user1ids = array_column($user1groups, 'groupid');
            $user2ids = array_column($user2groups, 'groupid');
            $inter = array_intersect($user1ids, $user2ids);

            if (count($inter) == count(array_intersect($inter, $bannedon))) {
                $bannedonall = TRUE;
            }
        }

        return $bannedonall;
    }

    public function createConversation($user1, $user2, $checkonly = FALSE)
    {
        $id = NULL;

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
            $id = $chats[0]['id'];

            $this->ensureAppearInList($id);
        } else if (!$checkonly) {
            # We don't have one.
            #
            # If the sender is banned on all the groups they have in common with the recipient, then they shouldn't
            # be able to communicate.
            $bannedonall = $this->bannedInCommon($user1, $user2);

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
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = $me ? $me->getId() : NULL;

            $this->ourFetch($id, $myid);

            # Ensure the two members are in the roster.
            $this->updateRoster($user1, NULL);
            $this->updateRoster($user2, NULL);

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

        return ($id);
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
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = $me ? $me->getId() : NULL;

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

        $u1id = presdef('user1', $this->chatroom, NULL);
        $u2id = presdef('user2', $this->chatroom, NULL);
        $gid = $this->chatroom['groupid'];
        
        $ret = $this->getAtts($this->publicatts);

        if (pres('groupid', $ret) && !$summary) {
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
                    $ctx = NULL;
                    $ret['user1'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, MODTOOLS, FALSE, FALSE, FALSE);

                    if (pres('group', $ret)) {
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
                    $ctx = NULL;
                    $ret['user2'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, MODTOOLS, FALSE, FALSE, FALSE);

                    if (pres('group', $ret)) {
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
                $ret['user1']['spammer'] = $s->getSpammerByUserid($u1id) !== NULL;
            }

            if ($u2id) {
                $ret['user2']['spammer'] = $s->getSpammerByUserid($u2id) !== NULL;
            }
        }

        # Icon for chat.  We assume that any user icons will have been created by this point.
        switch ($this->chatroom['chattype']) {
            case ChatRoom::TYPE_USER2USER:
                if ($this->chatroom['user1'] == $myid) {
                    $ret['icon'] = pres('u2defaultimage', $this->chatroom) ? ('https://' . USER_DOMAIN . '/defaultprofile.png') : ('https://' . IMAGE_DOMAIN . "/tuimg_" . $this->chatroom['u2imageid']  . ".jpg");
                } else {
                    $ret['icon'] = pres('u1defaultimage', $this->chatroom) ? ('https://' . USER_DOMAIN . '/defaultprofile.png') : ('https://' . IMAGE_DOMAIN . "/tuimg_" . $this->chatroom['u1imageid'] . ".jpg");
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

        # The name we return is not the one we created it with, which is internal.  
        switch ($this->chatroom['chattype']) {
            case ChatRoom::TYPE_USER2USER:
                if ($summary) {
                    # We use the name of the user who isn't us, because that's who we're chatting to.
                    $ret['name'] = $this->getUserName($myid == $u1id ? $u2id : $u1id);
                } else {
                    $ret['name'] = $u1id != $myid ? $ret['user1']['displayname'] : $ret['user2']['displayname'];
                }
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

        if (pres('lastmsg', $this->chatroom)) {
            $ret['lastmsg'] = $this->chatroom['lastmsg'];
            $ret['lastdate'] = $this->chatroom['lastdate'];

            $ret['snippet'] = $this->getSnippet($this->chatroom['chatmsgtype'], $this->chatroom['chatmsg']);
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

    public function getSnippet($msgtype, $chatmsg) {
        switch ($msgtype) {
            case ChatMessage::TYPE_ADDRESS: $ret = 'Address sent...'; break;
            case ChatMessage::TYPE_NUDGE: $ret = 'Nudged'; break;
            case ChatMessage::TYPE_SCHEDULE: $ret = 'Availability updated...'; break;
            case ChatMessage::TYPE_SCHEDULE_UPDATED: $ret = 'Availability updated...'; break;
            case ChatMessage::TYPE_COMPLETED: $ret = 'Item completed...'; break;
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

    public function unseenCountForUser($userid)
    {
        # Find if we have any unseen messages.  Exclude any pending review.
        $sql = "SELECT COUNT(*) AS count FROM chat_messages WHERE id > COALESCE((SELECT lastmsgseen FROM chat_roster WHERE chatid = ? AND userid = ? AND status != ? AND status != ?), 0) AND chatid = ? AND userid != ? AND reviewrequired = 0 AND reviewrejected = 0;";
        $counts = $this->dbhm->preQuery($sql, [
            $this->id,
            $userid,
            ChatRoom::STATUS_CLOSED,
            ChatRoom::STATUS_BLOCKED,
            $this->id,
            $userid
        ]);

        return ($counts[0]['count']);
    }

    public function allUnseenForUser($userid, $chattypes, $modtools = FALSE)
    {
        # Get all unseen messages.  We might have a cached version.
        $chatids = $this->listForUser($userid, $chattypes, NULL, $modtools);

        $ret = [];

        if ($chatids) {
            $idq = implode(',', $chatids);
            $sql = "SELECT chat_messages.* FROM chat_messages LEFT JOIN chat_roster ON chat_roster.chatid = chat_messages.chatid AND chat_roster.userid = ? WHERE chat_messages.chatid IN ($idq) AND chat_messages.userid != ? AND reviewrequired = 0 AND reviewrejected = 0 AND chat_messages.id > COALESCE(chat_roster.lastmsgseen, 0);";
            $ret = $this->dbhr->preQuery($sql, [ $userid, $userid]);
        }

        return ($ret);
    }

    public function countAllUnseenForUser($userid, $chattypes, $modtools = FALSE)
    {
        $chatids = $this->listForUser($userid, $chattypes, NULL, $modtools);

        $ret = 0;

        if ($chatids) {
            $idq = implode(',', $chatids);
            $sql = "SELECT COUNT(chat_messages.id) AS count FROM chat_messages LEFT JOIN chat_roster ON chat_roster.chatid = chat_messages.chatid AND chat_roster.userid = ? WHERE chat_messages.chatid IN ($idq) AND chat_messages.userid != ? AND reviewrequired = 0 AND reviewrejected = 0 AND chat_messages.id > COALESCE(chat_roster.lastmsgseen, 0);";
            $ret = $this->dbhr->preQuery($sql, [ $userid, $userid ])[0]['count'];
        }

        return ($ret);
    }

    public function updateMessageCounts() {
        # We store some information about the messages in the room itself.  We try to avoid duplicating information
        # like this, because it's asking for it to get out of step, but it means we can efficiently find the chat
        # rooms for a user in listForUser.
        $unheld = $this->dbhr->preQuery("SELECT CASE WHEN reviewrequired = 0 AND reviewrejected = 0 THEN 1 ELSE 0 END AS valid, COUNT(*) AS count FROM chat_messages WHERE chatid = ? GROUP BY (reviewrequired = 0 AND reviewrejected = 0) ORDER BY valid ASC;", [
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

    public function listForUser($userid, $chattypes = NULL, $search = NULL, $modtools = MODTOOLS, $chatid = NULL, $activelim = ChatRoom::ACTIVELIM)
    {
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

            $activesince = date("Y-m-d", strtotime($activelim));

            # We don't want to see non-empty chats where all the messages are held for review, because they are likely to
            # be spam.
            $countq = " AND (chat_rooms.msgvalid + chat_rooms.msginvalid = 0 OR chat_rooms.msgvalid > 0) ";

            # We don't want to see chats where you are a backup mod, unless we're specifically searching.
            $activeq = $search ? '' : ' AND active ';

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

            if (MODTOOLS && (!$chattypes || in_array(ChatRoom::TYPE_GROUP, $chattypes))) {
                # We want chats marked by groupid for which we are a member.  This is mod-only function.
                $thissql = "SELECT $atts FROM chat_rooms INNER JOIN $t1 ON chattype = 'Group' AND chat_rooms.groupid = t1.groupid LEFT JOIN chat_roster ON chat_roster.userid = $userid AND chat_rooms.id = chat_roster.chatid WHERE $chatq (status IS NULL OR status != 'Closed') $countq";
                #error_log("Group chats $sql, $userid");
                $sql = $sql == '' ? $thissql : "$sql UNION $thissql";
                #error_log("Add " . count($rooms) . " group chats using $sql");
            }

            #error_log("Chat rooms $sql");
            $rooms = $this->dbhr->preQuery($sql);

            if (count($rooms) > 0) {
                # We might have quite a lot of chats - speed up by reducing user fetches.
                $me = Session::whoAmI($this->dbhr, $this->dbhm);
                $ctx = NULL;
                $mepub = $me ? $me->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE) : NULL;

                foreach ($rooms as $room) {
                    $show = TRUE;

                    if ($search) {
                        # We want to apply a search filter.
                        $r = new ChatRoom($this->dbhr, $this->dbhm, $room['id']);
                        $name = $r->getPublic($me, $mepub, TRUE)['name'];

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
                    $rooms = $this->listForUser($userid, [$this->chatroom['chattype']], NULL, $this->id);
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
            error_log("upToDate: Set max to {$msg['max']} for $userid in room {$this->id} ");
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

    public function upToDateAll($myid) {
        $chatids = $this->listForUser($myid);

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
                error_log("upToDateAll: Add $myid into $chatid");
                $this->dbhm->preExec("INSERT INTO chat_roster (chatid, userid, lastmsgseen, lastmsgemailed, lastemailed) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE lastmsgseen = ?, lastmsgemailed = ?, lastemailed = NOW();",
                    [
                        $chatid,
                        $myid,
                        0,
                        0,
                        0,
                        0
                    ]);
            }
        }

        return $found;
    }

    public function updateRoster($userid, $lastmsgseen, $status = ChatRoom::STATUS_ONLINE)
    {
        # We have a unique key, and an update on current timestamp.
        #
        # Don't want to log these - lots of them.
        error_log("updateRoster: Add $userid into {$this->id}");
        $this->dbhm->preExec("INSERT INTO chat_roster (chatid, userid, lastip) VALUES (?,?,?) ON DUPLICATE KEY UPDATE lastip = ?, status = ?;",
            [
                $this->id,
                $userid,
                presdef('REMOTE_ADDR', $_SERVER, NULL),
                presdef('REMOTE_ADDR', $_SERVER, NULL),
                $status
            ],
            FALSE);

        if ($status == ChatRoom::STATUS_CLOSED || $status == ChatRoom::STATUS_BLOCKED) {
            # The Closed and Blocked statuses are special - they're per-room.  So we need to set it.
            $this->dbhm->preExec("UPDATE chat_roster SET status = ? WHERE chatid = ? AND userid = ?;", [
                $status,
                $this->id,
                $userid
            ], FALSE);
        }

        if ($lastmsgseen && !is_nan($lastmsgseen)) {
            # Update the last message seen - taking care not to go backwards, which can happen if we have multiple
            # windows open.
            $rc = $this->dbhm->preExec("UPDATE chat_roster SET lastmsgseen = ? WHERE chatid = ? AND userid = ? AND (lastmsgseen IS NULL OR lastmsgseen < ?);", [
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
                $n->notify($userid);
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
            $ctx = NULL;
            $rost['user'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE);
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
        $userids = [];
        #error_log("Notify $message exclude $excludeuser");

        switch ($this->chatroom['chattype']) {
            case ChatRoom::TYPE_USER2USER:
                # Notify both users.
                $userids[] = $this->chatroom['user1'];
                $userids[] = $this->chatroom['user2'];

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
                            if (!pres('settings', $mod) || pres('active', json_decode($mod['settings']))) {
                                $userids[] = $mod['userid'];
                            }
                        }
                    }
                }
                break;
            case ChatRoom::TYPE_USER2MOD:
                # Notify the initiator and the groups mods.
                $userids[] = $this->chatroom['user1'];
                $g = Group::get($this->dbhr, $this->dbhm, $this->chatroom['groupid']);
                $mods = $g->getMods();
                $userids = array_merge($userids, $mods);
                break;
        }

        # First Facebook.  Truncates after 120;
        $url = "chat/{$this->id}";
        $text = $name . " wrote: " . $message;
        $text = strlen($text) > 120 ? (substr($text, 0, 117) . '...') : $text;

        $f = new Facebook($this->dbhr, $this->dbhm);
        $count = 0;

        foreach ($userids as $userid) {
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

        # Now Push.
        $n = new PushNotifications($this->dbhr, $this->dbhm);
        foreach ($userids as $userid) {
            if ($userid != $excludeuser) {
                $n->notify($userid, FALSE);
            }
        }

        return ($count);
    }

    public function getMessagesForReview(User $user, $groupid, &$ctx)
    {
        # We want the messages for review for any group where we are a mod and the recipient of the chat message is
        # a member.
        $userid = $user->getId();
        $msgid = $ctx ? intval($ctx['msgid']) : 0;
        $groupq = $groupid ? " AND groupid = $groupid " : '';

        $sql = "SELECT chat_messages.id, chat_messages.chatid, chat_messages.userid, chat_messages_byemail.msgid, m1.groupid, m2.groupid AS groupidfrom, chat_messages_held.userid AS heldby, chat_messages_held.timestamp, chat_rooms.user1, chat_rooms.user2
FROM chat_messages
LEFT JOIN chat_messages_held ON chat_messages.id = chat_messages_held.msgid
LEFT JOIN chat_messages_byemail ON chat_messages_byemail.chatmsgid = chat_messages.id
INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid
INNER JOIN memberships m1 ON m1.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END)
AND m1.groupid IN (SELECT groupid FROM memberships WHERE chat_messages.id > ? AND memberships.userid = ? AND memberships.role IN ('Owner', 'Moderator') $groupq)
LEFT JOIN memberships m2 ON m2.userid = chat_messages.userid
AND m2.groupid IN (SELECT groupid FROM memberships WHERE chat_messages.id > ? AND memberships.userid = ? AND memberships.role IN ('Owner', 'Moderator') $groupq)
INNER JOIN groups ON m1.groupid = groups.id AND groups.type = 'Freegle'
ORDER BY chat_messages.id, m1.added ASC;";
        $msgs = $this->dbhr->preQuery($sql, [$msgid, $userid, $msgid, $userid]);
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

        $ctx2 = NULL;
        $u = new User($this->dbhr, $this->dbhm);
        $userlist = $u->getPublicsById($uids, NULL, FALSE, FALSE, $ctx2, TRUE, FALSE, FAlSE, FALSE, FALSE);

        foreach ($msgs as $msg) {
            # Return whether we're an active or not - client can filter.
            $processed[$msg['id']] = TRUE;

            $m = new ChatMessage($this->dbhr, $this->dbhm, $msg['id']);
            $thisone = $m->getPublic(TRUE, $userlist);

            if (pres('heldby', $msg)) {
                $u = User::get($this->dbhr, $this->dbhm, $msg['heldby']);
                $thisone['held'] = [
                    'id' => $u->getId(),
                    'name' => $u->getName(),
                    'timestamp' => ISODate($msg['timestamp']),
                    'email' => $u->getEmailPreferred()
                ];

                unset($thisone['heldby']);
            }

            # To avoid fetching the users again, ask for a summary and then fill them in from our in-hand copy.
            $r = new ChatRoom($this->dbhr, $this->dbhm, $msg['chatid']);
            $thisone['chatroom'] = $r->getPublic(NULL, NULL, TRUE);
            $u1id = presdef('user1', $thisone['chatroom'], NULL);
            $u2id = presdef('user2', $thisone['chatroom'], NULL);
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

            $thisone['date'] = ISODate($thisone['date']);
            $thisone['msgid'] = $msg['msgid'];

            $ctx['msgid'] = $msg['id'];

            $ret[] = $thisone;
        }

        return ($ret);
    }

    public function getMessages($limit = 100, $seenbyall = NULL, &$ctx = NULL, $refmsgsummary = FALSE)
    {
        $limit = intval($limit);
        $ctxq = $ctx ? (" AND chat_messages.id < " . intval($ctx['id']) . " ") : '';
        $seenfilt = $seenbyall === NULL ? '' : " AND seenbyall = $seenbyall ";

        # We do a join with the users table so that we can get the minimal information we need in a single query
        # rather than querying for each user by creating a User object.  Similarly, we fetched all message attributes
        # so that we can pass the fetched attributes into the constructor for each ChatMessage below.
        #
        # This saves us a lot of DB operations.
        $emailq1 = MODTOOLS ? ",chat_messages_byemail.msgid AS bymailid" : '';
        $emailq2 = MODTOOLS ? "LEFT JOIN chat_messages_byemail ON chat_messages_byemail.chatmsgid = chat_messages.id" : '';

        $sql = "SELECT chat_messages.*, 
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
        $lastmsg = NULL;

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        $modaccess = FALSE;

        if ($myid && $myid != $this->chatroom['user1'] && $myid != $this->chatroom['user2']) {
            #error_log("Check mod access $myid, {$this->chatroom['user1']}, {$this->chatroom['user2']}");
            $modaccess = $me->moderatorForUser($this->chatroom['user1']) ||
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
            $atts['bymailid'] = presdef('bymailid', $msg, NULL);

            $refmsgid = $m->getPrivate('refmsgid');

            if ($lastm &&
                ($lastm->getPrivate('type') == ChatMessage::TYPE_SCHEDULE_UPDATED ||
                    $lastm->getPrivate('type') == ChatMessage::TYPE_SCHEDULE) &&
                ($m->getPrivate('type') == ChatMessage::TYPE_SCHEDULE_UPDATED ||
                    $m->getPrivate('type') == ChatMessage::TYPE_SCHEDULE)) {
                # Duplicate schedule updates are common as people tweak them; remove the previous one, so that
                # we retain the latest dated one.  The contents will be the same as it will point to the same
                # schedule, but we want the date.
                array_pop($ret);
            }

            if (!$lastmsg || $atts['message'] != $lastmsg || $lastref != $refmsgid) {
                # We can get duplicate messages for a variety of reasons; suppress.
                $lastmsg = $atts['message'];
                $lastref = $refmsgid;

                #error_log("COnsider review {$atts['reviewrequired']}, {$msg['userid']}, $myid, $modaccess");
                if ($atts['reviewrequired'] && $msg['userid'] != $myid && !$modaccess) {
                    # This message is held for review, and we didn't send it.  So we shouldn't see it.
                } else if ($atts['reviewrejected']) {
                    # This message was reviewed and deemed unsuitable.  So we shouldn't see it.
                } else {
                    # We should return this one.
                    unset($atts['reviewrequired']);
                    unset($atts['reviewedby']);
                    unset($atts['reviewrejected']);
                    $atts['date'] = ISODate($atts['date']);

                    $atts['sameaslast'] = ($lastuser === $msg['userid']);

                    if (count($ret) > 0) {
                        $ret[count($ret) - 1]['sameasnext'] = ($lastuser === $msg['userid']);
                        $ret[count($ret) - 1]['gap'] = (strtotime($atts['date']) - strtotime($lastdate)) / 3600 > 1;
                    }

                    if (!array_key_exists($msg['userid'], $users)) {
                        $users[$msg['userid']] = [
                            'id' => $msg['userid'],
                            'displayname' => $msg['userdisplayname'],
                            'systemrole' => $msg['systemrole'],
                            'profile' => [
                                'url' => $msg['userimageurl'] ? $msg['userimageurl'] : ('https://' . IMAGE_DOMAIN . "/uimg_{$msg['userimageid']}.jpg"),
                                'turl' => $msg['userimageurl'] ? $msg['userimageurl'] : ('https://' . IMAGE_DOMAIN . "/tuimg_{$msg['userimageid']}.jpg"),
                                'default' => FALSE
                            ]
                        ];
                    }

                    if ($msg['type'] == ChatMessage::TYPE_INTERESTED) {
                        if (!pres('aboutme', $users[$msg['userid']])) {
                            # Find any "about me" info.
                            $u = User::get($this->dbhr, $this->dbhm, $msg['userid']);
                            $users[$msg['userid']]['aboutme'] = $u->getAboutMe();
                        }
                    }

                    $ret[] = $atts;
                    $lastuser = $msg['userid'];
                    $lastdate = $atts['date'];

                    $ctx['id'] = pres('id', $ctx) ? min($ctx['id'], $msg['id']) : $msg['id'];
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

    public function getMembersStatus($lastmessage, $delay = 600)
    {
        # There are some general restrictions on when we email:
        # - When we have a new message since our last email, we don't email more often than every 10 minutes, so that if
        #   someone keeps hammering away in chat we don't flood the recipient with emails.
        $ret = [];
        #error_log("Get not seen {$this->chatroom['chattype']}");

        if ($this->chatroom['chattype'] == ChatRoom::TYPE_USER2USER) {
            # This is a conversation between two users.  They're both in the roster so we can see what their last
            # seen message was and decide who to chase.  If they've blocked this chat we don't want to see it.
            #
            # Used to use lastmsgseen rather than lastmsgemailed - but that never stops if they don't visit the site.
            $sql = "SELECT chat_roster.* FROM chat_roster WHERE chatid = ? AND (status IS NULL OR status != 'Blocked') HAVING lastemailed IS NULL OR (lastmsgemailed < ? AND TIMESTAMPDIFF(SECOND, lastemailed, NOW()) >= $delay);";
            #error_log("$sql {$this->id}, $lastmessage");
            $users = $this->dbhr->preQuery($sql, [$this->id, $lastmessage]);
            foreach ($users as $user) {
                # What's the max message this user has either seen or been mailed?
                #error_log("Last {$user['lastmsgemailed']}, last message $lastmessage");
                $maxseen = presdef('lastmsgseen', $user, 0);
                $maxmailed = presdef('lastmsgemailed', $user, 0);
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
            $sql = "SELECT chat_roster.* FROM chat_roster INNER JOIN chat_rooms ON chat_rooms.id = chat_roster.chatid WHERE chatid = ? AND chat_roster.userid = chat_rooms.user1 HAVING lastemailed IS NULL OR lastemailed = '0000-00-00 00:00:00' OR (lastmsgemailed < ? AND TIMESTAMPDIFF(SECOND, lastemailed, NOW()) > $delay);";
            #error_log("Check User2Mod $sql, {$this->id}, $lastmessage");
            $users = $this->dbhr->preQuery($sql, [$this->id, $lastmessage]);

            foreach ($users as $user) {
                $maxseen = presdef('lastmsgseen', $user, 0);
                $maxmailed = presdef('lastmsgemailed', $user, 0);
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
                    $maxseen = presdef('lastmsgseen', $roster, 0);
                    $maxmailed = presdef('lastemailed', $roster, 0);
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

    public function notifyByEmail($chatid = NULL, $chattype, $emailoverride = NULL, $delay = 600, $allowpastschedules = FALSE, $since = "4 hours ago")
    {
        # We want to find chatrooms with messages which haven't been mailed to people.  We always email messages,
        # even if they are seen online.
        #
        # These could either be a group chatroom, or a conversation.  There aren't too many of the former, but there
        # could be a large number of the latter.  However we don't want to keep nagging people forever - so we are
        # only interested in rooms containing a message which was posted recently and which has not been mailed all
        # members - which is a much smaller set.
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        # We run this every minute, so we don't need to check too far back.  This keeps it quick.
        $reviewq = $chattype === ChatRoom::TYPE_USER2MOD ? '' : " AND reviewrequired = 0";
        $start = date('Y-m-d', strtotime($since));
        $chatq = $chatid ? " AND chatid = $chatid " : '';
        $sql = "SELECT DISTINCT chatid, chat_rooms.chattype, chat_rooms.groupid, chat_rooms.user1 FROM chat_messages INNER JOIN chat_rooms ON chat_messages.chatid = chat_rooms.id WHERE date >= ? AND mailedtoall = 0 AND seenbyall = 0 AND reviewrejected = 0 $reviewq AND chattype = ? $chatq;";
        #error_log("$sql, $start, $chattype");
        $chats = $this->dbhr->preQuery($sql, [$start, $chattype]);
        #error_log("Chats to scan " . count($chats));
        $notified = 0;

        foreach ($chats as $chat) {
            # Different members of the chat might have been mailed different messages.
            #error_log("Check chat {$chat['chatid']}");
            $r = new ChatRoom($this->dbhr, $this->dbhm, $chat['chatid']);
            $chatatts = $r->getPublic();
            $lastmaxmailed = $r->lastMailedToAll();
            $maxbugspot = 0;
            $sentsome = FALSE;
            $notmailed = $r->getMembersStatus($chatatts['lastmsg'], $delay);

            #error_log("Notmailed " . count($notmailed) . " with last message {$chatatts['lastmsg']}");

            foreach ($notmailed as $member) {
                # Now we have a member who has not been mailed the messages in this chat.
                #error_log("{$chat['chatid']} Not mailed {$member['userid']} last mailed {$member['lastmsgemailed']}");
                $other = $member['userid'] == $chatatts['user1']['id'] ? $chatatts['user2']['id'] : $chatatts['user1']['id'];
                $otheru = User::get($this->dbhr, $this->dbhm, $other);

                $thisu = User::get($this->dbhr, $this->dbhm, $member['userid']);

                # We email them if they have mails turned on, and even if they don't have any current memberships.
                # Although that runs the risk of annoying them if they've left, we also have to be able to handle
                # the case where someone replies from a different email which isn't a group membership, and we
                # want to notify that email.
                #
                # If this is a conversation between the user and a mod, we always mail the user.
                $emailnotifson = $thisu->notifsOn(User::NOTIFS_EMAIL, $r->getPrivate('groupid'));
                $forcemailfrommod = ($chat['chattype'] === ChatRoom::TYPE_USER2MOD && $chat['user1'] === $member['userid']);
                $mailson = $emailnotifson || $forcemailfrommod;
                #error_log("Consider mail {$member['userid']}, mails on " . $thisu->notifsOn(User::NOTIFS_EMAIL) . ", memberships " . count($thisu->getMemberships()));

                # Now collect a summary of what they've missed.  Don't include anything stupid old, in case they
                # have changed settings.
                #
                # For user2mod chats we want to mail messages even if they are held for chat review, because
                # chat review only shows user2user chats, and if we don't do this we could delay chats with mods
                # until the mod next visits the site.
                $mysqltime = date("Y-m-d", strtotime("Midnight 90 days ago"));
                $unmailedmsgs = $this->dbhr->preQuery("SELECT chat_messages.*, messages.type AS msgtype, messages.subject FROM chat_messages LEFT JOIN messages ON chat_messages.refmsgid = messages.id WHERE chatid = ? AND chat_messages.id > ? $reviewq AND reviewrejected = 0 AND chat_messages.date >= ? ORDER BY id ASC;",
                    [
                        $chat['chatid'],
                        $member['lastmsgemailed'] ? $member['lastmsgemailed'] : 0,
                        $mysqltime
                    ]);

                #error_log("Unseen " . var_export($unmailedmsgs, TRUE));

                if (count($unmailedmsgs) > 0) {
                    $textsummary = '';
                    $twigmessages = [];
                    $lastmsgemailed = 0;
                    $lastfrom = 0;
                    $lastmsg = NULL;
                    $justmine = TRUE;
                    $fromuid = NULL;
                    $firstid = NULL;

                    foreach ($unmailedmsgs as $unmailedmsg) {
                        # Message might be empty.
                        $unmailedmsg['message'] = strlen(trim($unmailedmsg['message'])) === 0 ? '(Empty message)' : $unmailedmsg['message'];

                        # Exclamation marks make emails look spammy, in conjunction with 'free' (which we use because,
                        # y'know, freegle) according to Litmus.  Remove them.
                        $unmailedmsg['message'] = str_replace('!', '.', $unmailedmsg['message']);

                        # Convert all emojis to smilies.  Obviously that's not right, but most of them are, and we want
                        # to get rid of the unicode.
                        $unmailedmsg['message'] = preg_replace('/\\\\u.*?\\\\u/', ':-)', $unmailedmsg['message']);

                        $maxbugspot = max($maxbugspot, $unmailedmsg['id']);

                        if ($mailson) {
                            if (!$firstid) {
                                # We're going to want to include the previous message as reply context, so we need
                                # to know the id of the first message we're sending.
                                $firstid = $unmailedmsg['id'];
                            }

                            # We can get duplicate messages for a variety of reasons.  Suppress them.
                            switch ($unmailedmsg['type']) {
                                case ChatMessage::TYPE_COMPLETED: {
                                    # There's no text stored for this - we invent it on the client.  Do so here
                                    # too.
                                    $thisone = $unmailedmsg['msgtype'] == Message::TYPE_OFFER ? "Sorry, '{$unmailedmsg['subject']}' is no longer available." : "Thanks, '{$unmailedmsg['subject']}' is no longer needed.";
                                    break;
                                }

                                case ChatMessage::TYPE_PROMISED: {
                                    $thisone = ($unmailedmsg['userid'] == $thisu->getId()) ? ("You promised \"" . $unmailedmsg['subject'] . "\" to " . $otheru->getName()) : ("Good news! " . $otheru->getName() . " has promised \"" . $unmailedmsg['subject'] . "\" to you.");
                                    break;
                                }

                                case ChatMessage::TYPE_INTERESTED: {
                                    $intsubj = "";

                                    if (count($unmailedmsgs) > 1) {
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

                                        if (pres('multiline', $atts)) {
                                            $thisone .= $atts['multiline'];

                                            if (pres('instructions', $atts)) {
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

                                case ChatMessage::TYPE_SCHEDULE:
                                case ChatMessage::TYPE_SCHEDULE_UPDATED: {
                                    $s = new Schedule($this->dbhr, $this->dbhm, $unmailedmsg['userid'], $allowpastschedules);
                                    $summ = $s->getSummary();
                                    $thisone = ($unmailedmsg['userid'] == $thisu->getId()) ? ("You updated your availability: $summ") : ($otheru->getName() . " has updated when they may be available: $summ");

                                    if (!strlen($summ)) {
                                        # No point sending this if there's no availability.
                                        continue 2;
                                    }
                                    break;
                                }

                                default: {
                                    # Use the text in the message.
                                    $thisone = $unmailedmsg['message'];
                                    break;
                                }
                            }

                            # Have we got any messages from someone else?
                            $justmine = ($unmailedmsg['userid'] != $thisu->getId()) ? FALSE : $justmine;
                            #error_log("From {$unmailedmsg['userid']} $thisone justmine? $justmine");

                            if (!$lastmsg || $lastmsg != $thisone) {
                                $messageu = User::get($this->dbhr, $this->dbhm, $unmailedmsg['userid']);
                                $fromname = $messageu->getName();
                                $fromuid = $messageu->getId();

                                #error_log("Message {$unmailedmsg['id']} from {$unmailedmsg['userid']} vs " . $thisu->getId());
                                $thistwig = [];

                                if ($unmailedmsg['type'] != ChatMessage::TYPE_COMPLETED) {
                                    # Only want to say someone wrote it if they did, which they didn't for system-
                                    # generated messages.
                                    if ($lastfrom != $unmailedmsg['userid']) {
                                        # Alternate colours.
                                        if ($unmailedmsg['userid'] == $thisu->getId()) {
                                            $thistwig['mine'] = TRUE;
                                            $thistwig['fromname'] = 'You';
                                            $thistwig['toname'] = $otheru->getId() ? $otheru->getName() : NULL;
                                        } else {
                                            $thistwig['mine'] = FALSE;
                                            $thistwig['fromname'] = $fromname;
                                        }
                                    }
                                }

                                $lastfrom = $unmailedmsg['userid'];

                                if ($unmailedmsg['imageid']) {
                                    $a = new Attachment($this->dbhr, $this->dbhm, $unmailedmsg['imageid'], Attachment::TYPE_CHAT_MESSAGE);
                                    $path = $a->getPath(FALSE);
                                    $thistwig['image'] = $path;
                                    $textsummary .= "Here's a picture: $path\r\n";
                                } else {
                                    $textsummary .= $thisone . "\r\n";
                                    $thistwig['message'] = $thisone;
                                }

                                $twigmessages[] = $thistwig;

                                $lastmsgemailed = max($lastmsgemailed, $unmailedmsg['id']);
                                $lastmsg = $thisone;
                            }
//                        } else {
//                            error_log("Skip {$member['userid']} as mails off");
                        }
                    }

                    #error_log("Consider justmine $justmine vs " . $thisu->notifsOn(User::NOTIFS_EMAIL_MINE) . " for " . $thisu->getId());
                    if (!$justmine || $thisu->notifsOn(User::NOTIFS_EMAIL_MINE)) {
                        if (count($twigmessages)) {
                            # As a subject, we should use the last "interested in" message in this chat - this is the
                            # most likely thing they are talking about.
                            $sql = "SELECT subject FROM messages INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id WHERE chatid = ? AND chat_messages.type = ? ORDER BY chat_messages.id DESC LIMIT 1;";
                            #error_log($sql . $chat['chatid']);
                            $subjs = $this->dbhr->preQuery($sql, [
                                $chat['chatid'],
                                ChatMessage::TYPE_INTERESTED
                            ]);
                            #error_log(var_export($subjs, TRUE));

                            $groupid = NULL;

                            switch ($chattype) {
                                case ChatRoom::TYPE_USER2USER:
                                    $subject = count($subjs) == 0 ? "You have a new message" : ("Re: " . str_replace('Re: ', '', $subjs[0]['subject']));
                                    $site = USER_SITE;
                                    break;
                                case ChatRoom::TYPE_USER2MOD:
                                    # We might either be notifying a user, or the mods.
                                    $g = Group::get($this->dbhr, $this->dbhm, $chat['groupid']);
                                    if ($member['role'] == User::ROLE_MEMBER) {
                                        $subject = "Your conversation with the " . $g->getPublic()['namedisplay'] . " volunteers";
                                        $site = USER_SITE;

                                        # The groupid is useful for TN.
                                        $groupid = $chat['groupid'];
                                    } else {
                                        $subject = "Member conversation on " . $g->getPrivate('nameshort') . " with " . $otheru->getName() . " (" . $otheru->getEmailPreferred() . ")";
                                        $site = MOD_SITE;
                                    }
                                    break;
                            }

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
                            $prevmsg = "";

                            if ($firstid) {
                                # Get the last substantive message in the chat before this one, if any are recent.
                                $earliest = date("Y-m-d", strtotime("Midnight 90 days ago"));
                                $prevmsgs = $this->dbhr->preQuery("SELECT chat_messages.* FROM chat_messages WHERE chatid = ? AND id < ? AND message IS NOT NULL AND date >= '$earliest' ORDER BY id DESC LIMIT 1;", [
                                    $chat['chatid'],
                                    $firstid
                                ]);

                                foreach ($prevmsgs as $p) {
                                    $prevmsg = $this->splitAndQuote($p['message']);
                                    $textsummary .= "\r\n\r\n$prevmsg";
                                }
                            }

                            $url = $thisu->loginLink($site, $member['userid'], '/chat/' . $chat['chatid'], User::SRC_CHATNOTIF);
                            $to = $thisu->getEmailPreferred();

                            #$to = 'log@ehibbert.org.uk';
                            #$to = 'activate@liveintent.com';

                            $jobads = $thisu->getJobAds();

                            try {
                                switch ($chattype) {
                                    case ChatRoom::TYPE_USER2USER:
                                        $html = $twig->render('chat_notify.html', [
                                            'unsubscribe' => $thisu->getUnsubLink($site, $member['userid'], User::SRC_CHATNOTIF),
                                            'fromid' => $otheru->getId(),
                                            'name' => $otheru->getName(),
                                            'reply' => $url,
                                            'messages' => $twigmessages,
                                            'backcolour' => '#FFF8DC',
                                            'email' => $to,
                                            'aboutme' => $otheru->getAboutMe()['text'],
                                            'prevmsg' => $prevmsg,
                                            'jobads' => $jobads['jobs'],
                                            'joblocation' => $jobads['location']
                                        ]);

                                        $sendname = $fromname;
                                        break;
                                    case ChatRoom::TYPE_USER2MOD:
                                        if ($member['role'] == User::ROLE_MEMBER) {
                                            $html = $twig->render('chat_notify.html', [
                                                'unsubscribe' => $thisu->getUnsubLink($site, $member['userid'], User::SRC_CHATNOTIF),
                                                'fromid' => $otheru->getId(),
                                                'name' => $fromname,
                                                'reply' => $url,
                                                'messages' => $twigmessages,
                                                'backcolour' => '#FFF8DC',
                                                'email' => $to,
                                                'prevmsg' => $prevmsg,
                                                'jobads' => $jobads['jobs'],
                                                'joblocation' => $jobads['location']
                                            ]);

                                            $sendname = $fromname;
                                        } else {
                                            $url = $thisu->loginLink($site, $member['userid'], '/modtools/chat/' . $chat['chatid'], User::SRC_CHATNOTIF);
                                            $html = $twig->render('chat_notify.html', [
                                                'unsubscribe' => $thisu->getUnsubLink($site, $member['userid'], User::SRC_CHATNOTIF),
                                                'fromid' => $otheru->getId(),
                                                'name' => $fromname,
                                                'reply' => $url,
                                                'messages' => $twigmessages,
                                                'ismod' => $thisu->isModerator(),
                                                'support' => SUPPORT_ADDR,
                                                'backcolour' => '#E8FEFB',
                                                'email' => $to,
                                                'prevmsg' => $prevmsg,
                                                'jobads' => $jobads['jobs'],
                                                'joblocation' => $jobads['location']
                                            ]);

                                            $sendname = 'Reply All';
                                        }
                                        break;
                                }
                            } catch (\Exception $e) { $html = ''; error_log("Twig failed with " . $e->getMessage()); }

                            # We ask them to reply to an email address which will direct us back to this chat.
                            #
                            # Use a special user for yahoo.co.uk to work around deliverability issues.
                            $domain = USER_DOMAIN;
                            #$domain = 'users2.ilovefreegle.org';
                            $replyto = 'notify-' . $chat['chatid'] . '-' . $member['userid'] . '@' . $domain;

                            # ModTools users should never get notified.
                            if ($to && strpos($to, MOD_SITE) === FALSE) {
                                error_log("Notify chat #{$chat['chatid']} $to for {$member['userid']} $subject last mailed will be $lastmsgemailed lastmax $lastmaxmailed");
                                try {
                                    #error_log("Our email " . $thisu->getOurEmail() . " for " . $thisu->getEmailPreferred());
                                    if (pres('seed', $member)) {
                                        # If this is a seed, we want to include the HTML if we would do so for the
                                        # recipient that it is a copy of.  That way we will analyse a representative
                                        # sample, rather than always send a plain text only email to the seeds.
                                        $includehtml = $member['seed']->getOurEmail();
                                        #error_log("Seed {$member['userid']} copy of " . $member['seed']->getId() . " use html? $includehtml html len " . strlen($html));
                                    } else {
                                        # We only include the HTML part if this is a user on our platform; otherwise
                                        # we just send a text bodypart containing the replies.  This means that our
                                        # messages to users who aren't on here look less confusing.
                                        $includehtml = $thisu->getOurEmail();
                                    }

                                    # Make the text summary longer, because this helps with spam detection according
                                    # to Litmus.
                                    $textsummary .= "\r\n\r\n-------\r\nThis is a text-only version of the message; you can also view this message in HTML if you have it turned on, and on the website.  We're adding this because short text messages don't always get delivered successfully.\r\n";
                                    $message = $this->constructMessage($thisu,
                                        $member['userid'],
                                        $thisu->getName(),
                                        $emailoverride ? $emailoverride : $to,
                                        $sendname,
                                        $replyto,
                                        $subject,
                                        $textsummary,
                                        $includehtml ? $html : NULL,
                                        $fromuid,
                                        $groupid);

                                    if ($message) {
                                        if ($chattype == ChatRoom::TYPE_USER2USER) {
                                            # Request read receipt.  We will often not get these for privacy reasons, but if
                                            # we do, it's useful to have to that we can display feedback to the sender.
                                            $headers = $message->getHeaders();
                                            $headers->addTextHeader('Disposition-Notification-To', "readreceipt-{$chat['chatid']}-{$member['userid']}-$lastmsgemailed@" . USER_DOMAIN);
                                            $headers->addTextHeader('Return-Receipt-To', "readreceipt-{$chat['chatid']}-{$member['userid']}-$lastmsgemailed@" . USER_DOMAIN);
                                        }

                                        Mail::addHeaders($message, Mail::CHAT, $thisu->getId());

                                        $this->mailer($message, $chattype == ChatRoom::TYPE_USER2USER ? $to : null);

                                        $sentsome = TRUE;

                                        if (!RETURN_PATH || !pres('seed', $member)) {
                                            $this->dbhm->preExec("UPDATE chat_roster SET lastemailed = NOW(), lastmsgemailed = ? WHERE userid = ? AND chatid = ?;", [
                                                $lastmsgemailed,
                                                $member['userid'],
                                                $chat['chatid']
                                            ]);

                                            if ($chattype == ChatRoom::TYPE_USER2USER && !$justmine) {
                                                # Send any SMS, but not if we're only mailing our own messages
                                                $thisu->sms('You have a new message.', 'https://' . $site . '/chat/' . $chat['chatid'] . '?src=sms');
                                            }

                                            $notified++;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    error_log("Send to {$member['userid']} failed with " . $e->getMessage());
                                }
                            }
                        }
                    }
                }
            }

            if ($sentsome) {
                # We have now mailed some more.  Note that this is resilient to new messages arriving while we were
                # looping above, because of lastmaxmailed, and we will mail those next time.
                $this->updateMaxMailed($chattype, $chat['chatid'], $lastmaxmailed);
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
                                $url = 'https://' . MOD_SITE . '/modtools/chat/' . $chat['id'];
                                $subject = "Member conversation on " . $g->getPrivate('nameshort') . " with " . $u->getName() . " (" . $u->getEmailPreferred() . ")";
                                $fromname = $u->getName();

                                $textsummary = '';
                                $htmlsummary = '';
                                $msgs = array_reverse($msgs);

                                foreach ($msgs as $unseenmsg) {
                                    if (pres('message', $unseenmsg)) {
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

                                    Mail::addHeaders($message, Mail::CHAT_CHASEUP_MODS, $thisu->getId());
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

                $time = (count($delays) > 0) ? calculate_median($delays) : NULL;

                $this->dbhm->preExec("REPLACE INTO users_replytime (userid, replytime) VALUES (?, ?);", [
                    $userid,
                    $time
                ]);

                $ret[$userid] = $time;
            }
        }

        return($ret);
    }

    public function nudge() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;
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
            $id = $lastmsg['id'];
        }

        # Create a message in the chat.
        return($id);
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

            if ($count) {
                #error_log("Expected received to {$expected['date']} {$expected['id']} from user #{$expected['userid']}");
                $this->dbhm->preExec("UPDATE chat_messages SET replyreceived = 1 WHERE id = ?;", [
                    $expected['id']
                ]);

                $this->dbhm->preExec("INSERT INTO users_expected (expecter, expectee, chatmsgid, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = ?;", [
                    $expected['userid'],
                    $other,
                    $expected['id'],
                    1,
                    1
                ]);

                $received++;
            } else {
                $this->dbhm->preExec("INSERT INTO users_expected (expecter, expectee, chatmsgid, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = ?;", [
                    $expected['userid'],
                    $other,
                    $expected['id'],
                    -1,
                    -1
                ]);

                $waiting++;
            }
        }
    }
}