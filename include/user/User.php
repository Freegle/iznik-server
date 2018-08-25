<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/session/Session.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/spam/Spam.php');
require_once(IZNIK_BASE . '/include/config/ModConfig.php');
require_once(IZNIK_BASE . '/include/message/MessageCollection.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
require_once(IZNIK_BASE . '/include/user/MembershipCollection.php');
require_once(IZNIK_BASE . '/include/user/PushNotifications.php');
require_once(IZNIK_BASE . '/include/user/Notifications.php');
require_once(IZNIK_BASE . '/include/misc/Location.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
require_once(IZNIK_BASE . '/mailtemplates/verifymail.php');
require_once(IZNIK_BASE . '/mailtemplates/welcome/forgotpassword.php');
require_once(IZNIK_BASE . '/mailtemplates/welcome/group.php');
require_once(IZNIK_BASE . '/mailtemplates/donations/thank.php');
require_once(IZNIK_BASE . '/mailtemplates/invite.php');
require_once(IZNIK_BASE . '/lib/wordle/functions.php');

use Jenssegers\ImageHash\ImageHash;
use Twilio\Rest\Client;

class User extends Entity
{
    # We have a cache of users, because we create users a _lot_, and this can speed things up significantly by avoiding
    # hitting the DB.
    static $cache = [];
    static $cacheDeleted = [];
    const CACHE_SIZE = 100;

    const KUDOS_NEWBIE = 'Newbie';
    const KUDOS_OCCASIONAL = 'Occasional';
    const KUDOS_FREQUENT = 'Frequent';
    const KUDOS_AVID = 'Avid';

    const RATING_UP = 'Up';
    const RATING_DOWN = 'Down';
    const RATING_MINE = 'Mine';
    const RATING_UNKNOWN = 'Unknown';

    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'firstname', 'lastname', 'fullname', 'systemrole', 'settings', 'yahooid', 'yahooUserId', 'newslettersallowed', 'relevantallowed', 'publishconsent', 'ripaconsent', 'bouncing', 'added', 'invitesleft');

    # Roles on specific groups
    const ROLE_NONMEMBER = 'Non-member';
    const ROLE_MEMBER = 'Member';
    const ROLE_MODERATOR = 'Moderator';
    const ROLE_OWNER = 'Owner';

    # Permissions
    const PERM_BUSINESS_CARDS = 'BusinessCardsAdmin';
    const PERM_NEWSLETTER = 'Newsletter';
    const PERM_NATIONAL_VOLUNTEERS = 'NationalVolunteers';
    const PERM_TEAMS = 'Teams';

    const HAPPY = 'Happy';
    const FINE = 'Fine';
    const UNHAPPY = 'Unhappy';

    # Role on site
    const SYSTEMROLE_SUPPORT = 'Support';
    const SYSTEMROLE_ADMIN = 'Admin';
    const SYSTEMROLE_USER = 'User';
    const SYSTEMROLE_MODERATOR = 'Moderator';

    const LOGIN_YAHOO = 'Yahoo';
    const LOGIN_FACEBOOK = 'Facebook';
    const LOGIN_GOOGLE = 'Google';
    const LOGIN_NATIVE = 'Native';
    const LOGIN_LINK = 'Link';

    const NOTIFS_EMAIL = 'email';
    const NOTIFS_EMAIL_MINE = 'emailmine';
    const NOTIFS_PUSH = 'push';
    const NOTIFS_FACEBOOK = 'facebook';
    const NOTIFS_APP = 'app';

    const INVITE_PENDING = 'Pending';
    const INVITE_ACCEPTED = 'Accepted';
    const INVITE_DECLINED = 'Declined';

    # Traffic sources
    const SRC_DIGEST = 'digest';
    const SRC_RELEVANT = 'relevant';
    const SRC_CHASEUP = 'chaseup';
    const SRC_CHASEUP_IDLE = 'beenawhile';
    const SRC_CHATNOTIF = 'chatnotif';
    const SRC_REPOST_WARNING = 'repostwarn';
    const SRC_FORGOT_PASSWORD = 'forgotpass';
    const SRC_PUSHNOTIF = 'pushnotif'; // From JS
    const SRC_TWITTER = 'twitter';
    const SRC_EVENT_DIGEST = 'eventdigest';
    const SRC_VOLUNTEERING_DIGEST = 'voldigest';
    const SRC_VOLUNTEERING_RENEWAL = 'volrenew';
    const SRC_NEWSLETTER = 'newsletter';
    const SRC_NOTIFICATIONS_EMAIL = 'notifemail';
    const SRC_NEWSFEED_DIGEST = 'newsfeeddigest';

    # Chat mod status
    const CHAT_MODSTATUS_MODERATED = 'Moderated';
    const CHAT_MODSTATUS_UNMODERATED = 'Unmoderated';

    /** @var  $log Log */
    private $log;
    var $user;
    private $memberships = NULL;
    private $ouremailid = NULL;
    private $emails = NULL;
    private $emailsord = NULL;
    private $profile = NULL;
    private $spammer = NULL;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        # We don't use Entity::fetch because we can reduce the number of DB ops in getPublic later by
        # doing a more complex query here.  This adds code complexity, but as you can imagine the performance of
        # this class is critical.
        $this->log = new Log($dbhr, $dbhm);
        $this->notif = new PushNotifications($dbhr, $dbhm);
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->name = 'user';
        $this->user = NULL;
        $this->id = NULL;
        $this->table = 'users';
        $this->profile = NULL;
        $this->spammer = [];

        if ($id) {
            $showspammer = FALSE;

            if (isset($_SESSION) && pres('id', $_SESSION) && $_SESSION['id'] != $id) {
                # We want to check for spammers.  If we're on ModTools and we have suitable rights then we can
                # return detailed info; otherwise just that they are on the list,.
                #
                # We don't do this for our own logged in user, otherwise we recurse to death.
                $me = whoAmI($dbhr, $dbhm);

                $showspammer = MODTOOLS && $me && in_array($me->getPrivate('systemrole'),[
                        User::ROLE_MODERATOR,
                        User::SYSTEMROLE_ADMIN,
                        User::SYSTEMROLE_SUPPORT
                    ]);
            }

            $sql = "SELECT users.*, users_images.id AS imageid, users_images.url AS imageurl, users_images.default AS imagedefault, spam_users.id AS spamid, spam_users.userid AS spamuserid, spam_users.byuserid AS spambyuserid, spam_users.added AS spamadded, spam_users.collection AS spamcollection, spam_users.reason AS spamreason FROM users LEFT JOIN users_images ON users_images.userid = users.id LEFT JOIN spam_users ON spam_users.userid = users.id WHERE users.id = ? ORDER BY imageid DESC LIMIT 1;";

            # Fetch the user and any profile image.  There are so many users that there is no point trying
            # to use the query cache.
            $users = $dbhr->preQuery($sql, [
                $id
            ], FALSE, FALSE);

            foreach ($users as &$user) {
                if ($user['spamid']) {
                    if ($showspammer) {
                        # Move spammer out of user attributes.
                        $this->spammer = [];
                        foreach (['id', 'userid', 'byuserid', 'added', 'collection', 'reason'] as $att) {
                            $this->spammer[$att]= $user['spam'. $att];
                            unset($user['spam' . $att]);
                        }

                        $this->spammer['added'] = ISODate($this->spammer['added']);
                    } else {
                        $this->spammer = TRUE;
                    }
                }

                if ($user['imageid']) {
                    # We found a profile.  Move it out of the user attributes.
                    $this->profile = [];
                    foreach (['id', 'url', 'default'] as $att) {
                        $this->profile[$att]= $user['image'. $att];
                        unset($user['image' . $att]);
                    }

                    if (!$this->profile['default']) {
                        # If it's a gravatar image we can return a thumbnail url that specifies a different size.
                        $turl = pres('url', $this->profile) ? $this->profile['url'] : ('https://' . IMAGE_DOMAIN . "/tuimg_{$this->profile['id']}.jpg");
                        $turl = strpos($turl, 'https://www.gravatar.com') === 0 ? str_replace('?s=200', '?s=100', $turl) : $turl;
                        $this->profile = [
                            'url' => pres('url', $this->profile) ? $this->profile['url'] : ('https://' . IMAGE_DOMAIN . "/uimg_{$this->profile['id']}.jpg"),
                            'turl' => $turl,
                            'default' => FALSE
                        ];
                    }
                }

                $this->user = $user;
                $this->id = $id;
            }
        }
    }

    public static function get(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $usecache = TRUE)
    {
        if ($id) {
            # We cache the constructed user.
            if ($usecache && array_key_exists($id, User::$cache) && User::$cache[$id]->getId() == $id) {
                # We found it.
                # @var User
                $u = User::$cache[$id];
                #error_log("Found $id in cache with " . $u->getId());

                if (!User::$cacheDeleted[$id]) {
                    # And it's not zapped - so we can use it.
                    #error_log("Not zapped");
                    return ($u);
                } else {
                    # It's zapped - so refetch.  It's important that we do this using the original DB handles, because
                    # whatever caused us to zap the cache might have done a modification operation which in turn
                    # zapped the SQL read cache.
                    #error_log("Zapped, refetch " . $id);
                    $u->fetch($u->dbhr, $u->dbhm, $id, 'users', 'user', $u->publicatts);
                    #error_log("Fetched $id as " . $u->getId() . " mod " . $u->isModerator());
                    User::$cache[$id] = $u;
                    User::$cacheDeleted[$id] = FALSE;
                    return ($u);
                }
            }
        }

        # Not cached.
        #error_log("$id not in cache");
        $u = new User($dbhr, $dbhm, $id);

        if ($id && count(User::$cache) < User::CACHE_SIZE) {
            # Store for next time
            #error_log("store $id in cache");
            User::$cache[$id] = $u;
            User::$cacheDeleted[$id] = FALSE;
        }

        return ($u);
    }

    public static function clearCache($id = NULL)
    {
        # Remove this user from our cache.
        #error_log("Clear $id from cache");
        if ($id) {
            User::$cacheDeleted[$id] = TRUE;
        } else {
            User::$cache = [];
            User::$cacheDeleted = [];
        }
    }

    public function hashPassword($pw)
    {
        return sha1($pw . PASSWORD_SALT);
    }

    public function login($pw, $force = FALSE)
    {
        # TODO lockout
        if ($this->id) {
            $pw = $this->hashPassword($pw);
            $logins = $this->getLogins(TRUE);
            foreach ($logins as $login) {
                if ($force || ($login['type'] == User::LOGIN_NATIVE && $login['uid'] == $this->id && $pw == $login['credentials'])) {
                    $s = new Session($this->dbhr, $this->dbhm);
                    $s->create($this->id);

                    # Anyone who has logged in to our site has given RIPA consent.
                    $this->dbhm->preExec("UPDATE users SET ripaconsent = 1 WHERE id = ?;",
                        [
                            $this->id
                        ]);
                    User::clearCache($this->id);

                    $l = new Log($this->dbhr, $this->dbhm);
                    $l->log([
                        'type' => Log::TYPE_USER,
                        'subtype' => Log::SUBTYPE_LOGIN,
                        'byuser' => $this->id,
                        'text' => 'Using email/password'
                    ]);

                    return (TRUE);
                }
            }
        }

        return (FALSE);
    }

    public function linkLogin($key)
    {
        $ret = FALSE;

        if (presdef('id', $_SESSION, NULL) != $this->id) {
            # We're not already logged in as this user.
            $sql = "SELECT * FROM users_logins WHERE userid = ? AND type = ? AND credentials = ?;";
            $logins = $this->dbhr->preQuery($sql, [$this->id, User::LOGIN_LINK, $key], FALSE, FALSE);
            foreach ($logins as $login) {
                # We found a match - log them in.
                $s = new Session($this->dbhr, $this->dbhm);
                $s->create($this->id);

                $l = new Log($this->dbhr, $this->dbhm);
                $l->log([
                    'type' => Log::TYPE_USER,
                    'subtype' => Log::SUBTYPE_LOGIN,
                    'byuser' => $this->id,
                    'text' => 'Using link'
                ]);

                $ret = TRUE;
            }
        }

        return ($ret);
    }

    public function getBounce()
    {
        return ("bounce-{$this->id}-" . time() . "@" . USER_DOMAIN);
    }

    public function getName($default = TRUE)
    {
        # We may or may not have the knowledge about how the name is split out, depending
        # on the sign-in mechanism.
        $name = NULL;
        if ($this->user['fullname']) {
            $name = $this->user['fullname'];
        } else if ($this->user['firstname'] || $this->user['lastname']) {
            $name = $this->user['firstname'] . ' ' . $this->user['lastname'];
        }

        # Make sure we don't return an email if somehow one has snuck in.
        $name = ($name && strpos($name, '@') !== FALSE) ? substr($name, 0, strpos($name, '@')) : $name;

        if ($default && strlen(trim($name)) === 0) {
            $name = MODTOOLS ? 'Someone' : 'A freegler';
        }

        return ($name);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function create($firstname, $lastname, $fullname, $reason = '', $yahooUserId = NULL, $yahooid = NULL)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        try {
            $src = presdef('src', $_SESSION, NULL);
            $rc = $this->dbhm->preExec("INSERT INTO users (firstname, lastname, fullname, yahooUserId, yahooid, source) VALUES (?, ?, ?, ?, ?, ?)",
                [$firstname, $lastname, $fullname, $yahooUserId, $yahooid, $src]);
            $id = $this->dbhm->lastInsertId();
        } catch (Exception $e) {
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'users', 'user', $this->publicatts);
            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_CREATED,
                'user' => $id,
                'byuser' => $me ? $me->getId() : NULL,
                'text' => $this->getName() . " #$id " . $reason
            ]);

            # Encourage them to introduce themselves.
            $n = new Notifications($this->dbhr, $this->dbhm);
            $n->add(NULL, $id, Notifications::TYPE_ABOUT_ME, NULL, NULL, NULL);

            return ($id);
        } else {
            return (NULL);
        }
    }

    public function inventPassword()
    {
        $lengths = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/distinct_word_lengths.json'), true);
        $bigrams = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/word_start_bigrams.json'), true);
        $trigrams = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/trigrams.json'), true);

        $pw = '';

        do {
            $length = \Wordle\array_weighted_rand($lengths);
            $start = \Wordle\array_weighted_rand($bigrams);
            $pw .= \Wordle\fill_word($start, $length, $trigrams);
        } while (strlen($pw) < 6);

        $pw = strtolower($pw);
        return ($pw);
    }

    public function findByYahooUserId($id)
    {
        # Take care not to pick up empty or null else that will cause is to overmerge.
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE yahooUserId = ? AND yahooUserId IS NOT NULL AND LENGTH(yahooUserId) > 0;", [$id]);
        if (count($users) == 1) {
            return ($users[0]['id']);
        }

        return (NULL);
    }

    public function getEmails($recent = FALSE, $nobouncing = FALSE)
    {
        # Don't return canon - don't need it on the client.
        $ordq = $recent ? 'id' : 'preferred';

        if (!$this->emails || $ordq != $this->emailsord) {
            $bounceq = $nobouncing ? " AND bounced IS NULL " : '';
            $sql = "SELECT id, userid, email, preferred, added, validated FROM users_emails WHERE userid = ? $bounceq ORDER BY $ordq DESC, email ASC;";
            #error_log("$sql, {$this->id}");
            $this->emails = $this->dbhr->preQuery($sql, [$this->id]);
            $this->emailsord = $ordq;

            foreach ($this->emails as &$email) {
                $email['ourdomain'] = ourDomain($email['email']);
            }
        }

        return ($this->emails);
    }

    public function getEmailPreferred()
    {
        # This gets the email address which we think the user actually uses.  So we pay attention to:
        # - the preferred flag, which gets set by end user action
        # - the date added, as most recently added emails are most likely to be right
        # - exclude our own invented mails
        # - exclude any yahoo groups mails which have snuck in.
        $emails = $this->getEmails();
        $ret = NULL;

        foreach ($emails as $email) {
            if (!ourDomain($email['email']) && strpos($email['email'], '@yahoogroups.') === FALSE) {
                $ret = $email['email'];
                break;
            }
        }

        return ($ret);
    }

    public function getOurEmail($emails = NULL)
    {
        $emails = $emails ? $emails : $this->dbhr->preQuery("SELECT id, userid, email, preferred, added, validated FROM users_emails WHERE userid = ? ORDER BY preferred DESC, added DESC;",
            [$this->id]);
        $ret = NULL;

        foreach ($emails as $email) {
            if (ourDomain($email['email'])) {
                $ret = $email['email'];
                break;
            }
        }

        return ($ret);
    }

    public function getAnEmailId()
    {
        $emails = $this->dbhr->preQuery("SELECT id FROM users_emails WHERE userid = ? ORDER BY preferred DESC;",
            [$this->id]);
        return (count($emails) == 0 ? NULL : $emails[0]['id']);
    }

    public function isApprovedMember($groupid)
    {
        $membs = $this->dbhr->preQuery("SELECT id FROM memberships WHERE userid = ? AND groupid = ? AND collection = 'Approved';", [$this->id, $groupid]);
        return (count($membs) > 0 ? $membs[0]['id'] : NULL);
    }

    public function getEmailAge($email)
    {
        $emails = $this->dbhr->preQuery("SELECT TIMESTAMPDIFF(HOUR, added, NOW()) AS ago FROM users_emails WHERE email LIKE ?;", [
            $email
        ]);

        return (count($emails) > 0 ? $emails[0]['ago'] : NULL);
    }

    public function getEmailForYahooGroup($groupid, $oursonly = FALSE, $approvedonly = TRUE)
    {
        # Any of the emails will do.
        $emails = $this->getEmailsForYahooGroup($groupid, $oursonly, $approvedonly);
        $eid = count($emails) > 0 ? $emails[0][0] : NULL;
        $email = count($emails) > 0 ? $emails[0][1] : NULL;
        return ([$eid, $email]);
    }

    public function getEmailsForYahooGroup($groupid, $oursonly = FALSE, $approvedonly)
    {
        $emailq = "";

        # We must check memberships_yahoo rather than memberships, because memberships can get set to Approved by
        # someone approving the user on the platform, whereas memberships_yahoo only gets set when we really
        # know that the member has been approved onto Yahoo.
        $collq = $approvedonly ? " AND memberships_yahoo.collection = 'Approved' " : '';

        if ($oursonly) {
            # We are looking for a group email which we host.
            foreach (explode(',', OURDOMAINS) as $domain) {
                $emailq .= $emailq == "" ? " email LIKE '%$domain'" : " OR email LIKE '%$domain'";
            }

            $emailq = " AND ($emailq)";
        }

        # Return most recent first.  This helps in the message_waitingforyahoo case where we add extra emails.
        $sql = "SELECT memberships_yahoo.emailid, users_emails.email FROM memberships_yahoo INNER JOIN memberships ON memberships.id = memberships_yahoo.membershipid INNER JOIN users_emails ON memberships_yahoo.emailid = users_emails.id WHERE memberships.userid = ? AND groupid = ? $emailq $collq ORDER BY users_emails.id DESC;";
        #error_log($sql . ", {$this->id}, $groupid");
        $emails = $this->dbhr->preQuery($sql, [
            $this->id,
            $groupid
        ]);

        $ret = [];
        foreach ($emails as $email) {
            $ret[] = [$email['emailid'], $email['email']];
        }

        return ($ret);
    }

    public function getIdForEmail($email)
    {
        # Email is a unique key but conceivably we could be called with an email for another user.
        $ids = $this->dbhr->preQuery("SELECT id, userid FROM users_emails WHERE (canon = ? OR canon = ?);", [
            User::canonMail($email),
            User::canonMail($email, TRUE)
        ]);

        foreach ($ids as $id) {
            return ($id);
        }

        return (NULL);
    }

    public function getEmailById($id)
    {
        $emails = $this->dbhr->preQuery("SELECT email FROM users_emails WHERE id = ?;", [
            $id
        ]);

        $ret = NULL;

        foreach ($emails as $email) {
            $ret = $email['email'];
        }

        return ($ret);
    }

    public function findByEmail($email)
    {
        if (preg_match('/.*\-(.*)\@' . USER_DOMAIN . '/', $email, $matches)) {
            # Our own email addresses have the UID in there.  This will match even if the email address has
            # somehow been removed from the list.
            $uid = $matches[1];
            $users = $this->dbhr->preQuery("SELECT id FROM users WHERE id = ?;", [
                $uid
            ]);

            foreach ($users as $user) {
                return ($user['id']);
            }
        }

        # Take care not to pick up empty or null else that will cause is to overmerge.
        #
        # Use canon to match - that handles variant TN addresses or % addressing.
        $users = $this->dbhr->preQuery("SELECT userid FROM users_emails WHERE (canon = ? OR canon = ?) AND canon IS NOT NULL AND LENGTH(canon) > 0;",
            [
                User::canonMail($email),
                User::canonMail($email, TRUE)
            ]);

        foreach ($users as $user) {
            return ($user['userid']);
        }

        return (NULL);
    }

    public function findByEmailHash($hash)
    {
        # Take care not to pick up empty or null else that will cause is to overmerge.
        $users = $this->dbhr->preQuery("SELECT userid FROM users_emails WHERE md5hash LIKE ? AND md5hash IS NOT NULL AND LENGTH(md5hash) > 0;",
            [
                User::canonMail($hash),
            ]);

        foreach ($users as $user) {
            return ($user['userid']);
        }

        return (NULL);
    }

    public function findByYahooId($id)
    {
        # Take care not to pick up empty or null else that will cause is to overmerge.
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE yahooid = ? AND yahooid IS NOT NULL AND LENGTH(yahooid) > 0;",
            [$id]);

        foreach ($users as $user) {
            return ($user['id']);
        }

        return (NULL);
    }

    # TODO The $old paramter can be retired after 01/07/17
    public static function canonMail($email, $old = FALSE)
    {
        # Googlemail is Gmail really in US and UK.
        $email = str_replace('@googlemail.', '@gmail.', $email);
        $email = str_replace('@googlemail.co.uk', '@gmail.co.uk', $email);

        # Canonicalise TN addresses.
        if (preg_match('/(.*)\-(.*)(@user.trashnothing.com)/', $email, $matches)) {
            $email = $matches[1] . $matches[3];
        }

        # Remove plus addressing, which is sometimes used by spammers as a trick, except for Facebook where it
        # appears to be genuinely used for routing to distinct users.
        if (preg_match('/(.*)\+(.*)(@.*)/', $email, $matches) && strpos($email, '@proxymail.facebook.com') === FALSE) {
            $email = $matches[1] . $matches[3];
        }

        # Remove dots in LHS, which are ignored by gmail and can therefore be used to give the appearance of separate
        # emails.
        #
        # TODO For migration purposes we can remove them from all domains.  This will disappear in time.
        $p = strpos($email, '@');

        if ($p !== FALSE) {
            $lhs = substr($email, 0, $p);
            $rhs = substr($email, $p);

            if (stripos($rhs, '@gmail') !== FALSE || stripos($rhs, '@googlemail') !== FALSE || $old) {
                $lhs = str_replace('.', '', $lhs);
            }

            # Remove dots from the RHS - saves a little space and is the format we have historically used.
            # Very unlikely to introduce ambiguity.
            $email = $lhs . str_replace('.', '', $rhs);
        }

        return ($email);
    }

    public function addEmail($email, $primary = 1, $changeprimary = TRUE)
    {
        # Invalidate cache.
        $this->emails = NULL;

        if (stripos($email, '-owner@yahoogroups.co') !== FALSE ||
            stripos($email, '-volunteers@' . GROUP_DOMAIN) !== FALSE) {
            # We don't allow people to add Yahoo owner addresses as the address of an individual user, or
            # the volunteer addresses.
            $rc = NULL;
        } else {
            # If the email already exists in the table, then that's fine.  But we don't want to use INSERT IGNORE as
            # that scales badly for clusters.
            $canon = User::canonMail($email);

            # Don't cache - lots of emails so don't want to flood the query cache.
            $sql = "SELECT SQL_NO_CACHE id, preferred FROM users_emails WHERE userid = ? AND email = ?;";
            $emails = $this->dbhm->preQuery($sql, [
                $this->id,
                $email
            ]);

            if (count($emails) == 0) {
                $sql = "INSERT IGNORE INTO users_emails (userid, email, preferred, canon, backwards) VALUES (?, ?, ?, ?, ?)";
                $rc = $this->dbhm->preExec($sql,
                    [$this->id, $email, $primary, $canon, strrev($canon)]);
                $rc = $this->dbhm->lastInsertId();

                if ($rc && $primary) {
                    # Make sure no other email is flagged as primary
                    $this->dbhm->preExec("UPDATE users_emails SET preferred = 0 WHERE userid = ? AND id != ?;", [
                        $this->id,
                        $rc
                    ]);
                }
            } else {
                $rc = $emails[0]['id'];

                if ($changeprimary && $primary != $emails[0]['preferred']) {
                    # Change in status.
                    $this->dbhm->preExec("UPDATE users_emails SET preferred = ? WHERE id = ?;", [
                        $primary,
                        $rc
                    ]);
                }

                if ($primary) {
                    # Make sure no other email is flagged as primary
                    $this->dbhm->preExec("UPDATE users_emails SET preferred = 0 WHERE userid = ? AND id != ?;", [
                        $this->id,
                        $rc
                    ]);

                    # If we've set an email we might no longer be bouncing.
                    $this->unbounce($rc, FALSE);
                }
            }
        }

        return ($rc);
    }

    public function unbounce($emailid, $log)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        if ($log) {
            $l = new Log($this->dbhr, $this->dbhm);

            $l->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_UNBOUNCE,
                'user' => $this->id,
                'byuser' => $myid
            ]);
        }

        if ($emailid) {
            $this->dbhm->preExec("UPDATE bounces_emails SET reset = 1 WHERE emailid = ?;", [$emailid]);
        }

        $this->dbhm->preExec("UPDATE users SET bouncing = 0 WHERE id = ?;", [$this->id]);
    }

    public function removeEmail($email)
    {
        # Invalidate cache.
        $this->emails = NULL;

        $rc = $this->dbhm->preExec("DELETE FROM users_emails WHERE userid = ? AND email = ?;",
            [$this->id, $email]);
        return ($rc);
    }

    private function updateSystemRole($role)
    {
        #error_log("Update systemrole $role on {$this->id}");
        User::clearCache($this->id);

        if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
            $sql = "UPDATE users SET systemrole = ? WHERE id = ? AND systemrole = ?;";
            $this->dbhm->preExec($sql, [User::SYSTEMROLE_MODERATOR, $this->id, User::SYSTEMROLE_USER]);
            $this->user['systemrole'] = $this->user['systemrole'] == User::SYSTEMROLE_USER ?
                User::SYSTEMROLE_MODERATOR : $this->user['systemrole'];
        } else if ($this->user['systemrole'] == User::SYSTEMROLE_MODERATOR) {
            # Check that we are still a mod on a group, otherwise we need to demote ourselves.
            $sql = "SELECT id FROM memberships WHERE userid = ? AND role IN (?,?);";
            $roles = $this->dbhr->preQuery($sql, [
                $this->id,
                User::ROLE_MODERATOR,
                User::ROLE_OWNER
            ]);

            if (count($roles) == 0) {
                $sql = "UPDATE users SET systemrole = ? WHERE id = ?;";
                $this->dbhm->preExec($sql, [User::SYSTEMROLE_USER, $this->id]);
                $this->user['systemrole'] = User::SYSTEMROLE_USER;
            }
        }
    }

    public function postToCollection($groupid)
    {
        # Which collection should we post to?  If this is a group on Yahoo then ourPostingStatus will be NULL.  We
        # will post to Pending, and send the message to Yahoo; if the user is unmoderated on there it will come back
        # to us and move to Approved.  If there is a value for ourPostingStatus, then this is a native group and
        # we will use that.
        $ps = $this->getMembershipAtt($groupid, 'ourPostingStatus');
        $coll = (!$ps || $ps == Group::POSTING_MODERATED) ? MessageCollection::PENDING : MessageCollection::APPROVED;
        return ($coll);
    }

    private function addYahooMembership($membershipid, $role, $emailid, $collection)
    {
        $sql = "REPLACE INTO memberships_yahoo (membershipid, role, emailid, collection) VALUES (?,?,?,?);";
        $this->dbhm->preExec($sql, [
            $membershipid,
            $role,
            $emailid,
            $collection
        ]);
    }

    public function addMembership($groupid, $role = User::ROLE_MEMBER, $emailid = NULL, $collection = MembershipCollection::APPROVED, $message = NULL, $byemail = NULL, $addedhere = TRUE)
    {
        $this->memberships = NULL;
        $me = whoAmI($this->dbhr, $this->dbhm);
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);

        Session::clearSessionCache();

        # Check if we're banned
        $sql = "SELECT * FROM users_banned WHERE userid = ? AND groupid = ?;";
        $banneds = $this->dbhr->preQuery($sql, [
            $this->id,
            $groupid
        ]);

        foreach ($banneds as $banned) {
            error_log("{$this->id} on $groupid is banned");
            return (FALSE);
        }

        # We don't want to use REPLACE INTO because the membershipid is a foreign key in some tables (such as
        # memberships_yahoo), and if the membership already exists, then this would cause us to delete and re-add it,
        # which would result in the row in the child table being deleted.
        #
        #error_log("Add membership role $role for {$this->id} to $groupid with $emailid collection $collection");
        $existing = $this->dbhm->preQuery("SELECT COUNT(*) AS count FROM memberships WHERE userid = ? AND groupid = ? AND collection = ?;", [
            $this->id,
            $groupid,
            $collection
        ]);

        $rc = $this->dbhm->preExec("INSERT INTO memberships (userid, groupid, role, collection) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), role = ?, collection = ?;", [
            $this->id,
            $groupid,
            $role,
            $collection,
            $role,
            $collection
        ]);
        $membershipid = $this->dbhm->lastInsertId();

        # We added it if it wasn't there before and the INSERT worked.
        $added = $this->dbhm->rowsAffected() && $existing[0]['count'] == 0;

        if ($rc && $emailid && $g->onYahoo()) {
            $this->addYahooMembership($membershipid, $role, $emailid, $collection);
        }

        # Record the operation for abuse detection.
        $this->dbhm->preExec("INSERT INTO memberships_history (userid, groupid, collection) VALUES (?,?,?);", [
            $this->id,
            $groupid,
            $collection
        ]);

        # We might need to update the systemrole.
        #
        # Not the end of the world if this fails.
        $this->updateSystemRole($role);

        // @codeCoverageIgnoreStart
        if ($byemail) {
            list ($transport, $mailer) = getMailer();
            $message = Swift_Message::newInstance()
                ->setSubject("Welcome to " . $g->getPrivate('nameshort'))
                ->setFrom($g->getAutoEmail())
                ->setReplyTo($g->getModsEmail())
                ->setTo($byemail)
                ->setDate(time())
                ->setBody("Pleased to meet you.");
            $headers = $message->getHeaders();
            $headers->addTextHeader('X-Freegle-Mail-Type', 'Added');
            $this->sendIt($mailer, $message);
        }
        // @codeCoverageIgnoreStart

        if ($added) {
            # The membership didn't already exist.  We might want to send a welcome mail.
            $atts = $g->getPublic();

            if (($addedhere) && ($atts['welcomemail'] || $message) && $collection == MembershipCollection::APPROVED) {
                # They are now approved.  We need to send a per-group welcome mail.
                $to = $this->getEmailPreferred();

                if ($to) {
                    $welcome = $message ? $message : $atts['welcomemail'];
                    $html = welcome_group(USER_SITE, $atts['profile'] ? $atts['profile'] : USERLOGO, $to, $atts['namedisplay'], nl2br($welcome));
                    list ($transport, $mailer) = getMailer();
                    $message = Swift_Message::newInstance()
                        ->setSubject("Welcome to " . $atts['namedisplay'])
                        ->setFrom([$g->getAutoEmail() => $atts['namedisplay'] . ' Volunteers'])
                        ->setReplyTo([$g->getModsEmail() => $atts['namedisplay'] . ' Volunteers'])
                        ->setTo($to)
                        ->setDate(time())
                        ->setBody($welcome);

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($html);
                    $message->attach($htmlPart);

                    $this->sendIt($mailer, $message);
                }
            }

            $l = new Log($this->dbhr, $this->dbhm);
            $l->log([
                'type' => Log::TYPE_GROUP,
                'subtype' => $collection == MembershipCollection::PENDING ? Log::SUBTYPE_APPLIED : Log::SUBTYPE_JOINED,
                'user' => $this->id,
                'byuser' => $me ? $me->getId() : NULL,
                'groupid' => $groupid
            ]);
        }

        # Check whether this user now counts as a possible spammer.
        $s = new Spam($this->dbhr, $this->dbhm);
        $s->checkUser($this->id);

        if ($rc && $collection == MembershipCollection::PENDING && $g->getSetting('approvemembers', FALSE)) {
            # Let the user know that they need to wait.
            $n = new Notifications($this->dbhr, $this->dbhm);
            $n->add(NULL, $this->id, Notifications::TYPE_MEMBERSHIP_PENDING, NULL, 'https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort'));
        }

        return ($rc);
    }

    public function isRejected($groupid)
    {
        # We use this to check if a member has recently been rejected.  We call it when we are dealing with a
        # member that we think should be pending, to check that they haven't been rejected and therefore
        # we shouldn't continue processing them.
        #
        # This is rather than checking the current collection they're in, because there are some awkward timing
        # windows.  For example:
        # - Trigger Yahoo application
        # - Member sync via plugin - not yet on Pending on Yahoo
        # - Delete membership
        # - Then asked to confirm application
        #
        # TODO This lasts forever.  Probably it shouldn't.
        $logs = $this->dbhr->preQuery("SELECT id FROM logs WHERE user = ? AND groupid = ? AND type = ? AND subtype = ?;", [
            $this->id,
            $groupid,
            Log::TYPE_USER,
            Log::SUBTYPE_REJECTED
        ]);

        $ret = count($logs) > 0;

        return ($ret);
    }

    public function isPendingMember($groupid)
    {
        $ret = false;
        $sql = "SELECT userid FROM memberships WHERE userid = ? AND groupid = ? AND collection = ?;";
        $membs = $this->dbhr->preQuery($sql, [
            $this->id,
            $groupid,
            MembershipCollection::PENDING
        ]);

        return (count($membs) > 0);
    }

    private function cacheMemberships()
    {
        # We get all the memberships in a single call, because some members are on many groups and this can
        # save hundreds of calls to the DB.
        if (!$this->memberships) {
            $this->memberships = [];

            $membs = $this->dbhr->preQuery("SELECT memberships.*, groups.type FROM memberships INNER JOIN groups ON groups.id = memberships.groupid WHERE userid = ?;", [$this->id]);
            foreach ($membs as $memb) {
                $this->memberships[$memb['groupid']] = $memb;
            }
        }

        return ($this->memberships);
    }

    public function clearMembershipCache()
    {
        $this->memberships = NULL;
    }

    public function getMembershipAtt($groupid, $att)
    {
        $this->cacheMemberships();
        $val = NULL;
        if (pres($groupid, $this->memberships)) {
            $val = presdef($att, $this->memberships[$groupid], NULL);
        }

        return ($val);
    }

    public function setMembershipAtt($groupid, $att, $val)
    {
        $this->clearMembershipCache();
        Session::clearSessionCache();
        $sql = "UPDATE memberships SET $att = ? WHERE groupid = ? AND userid = ?;";
        $rc = $this->dbhm->preExec($sql, [
            $val,
            $groupid,
            $this->id
        ]);

        return ($rc);
    }

    public function setYahooMembershipAtt($groupid, $emailid, $att, $val)
    {
        $sql = "UPDATE memberships_yahoo SET $att = ? WHERE membershipid = (SELECT id FROM memberships WHERE userid = ? AND groupid = ?) AND emailid = ?;";
        $rc = $this->dbhm->preExec($sql, [
            $val,
            $this->id,
            $groupid,
            $emailid
        ]);

        return ($rc);
    }

    public function removeMembership($groupid, $ban = FALSE, $spam = FALSE, $byemail = NULL)
    {
        $this->clearMembershipCache();
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $me = whoAmI($this->dbhr, $this->dbhm);
        $meid = $me ? $me->getId() : NULL;

        // @codeCoverageIgnoreStart
        //
        // Let them know.
        if ($byemail) {
            list ($transport, $mailer) = getMailer();
            $message = Swift_Message::newInstance()
                ->setSubject("Farewell from " . $g->getPrivate('nameshort'))
                ->setFrom($g->getAutoEmail())
                ->setReplyTo($g->getModsEmail())
                ->setTo($byemail)
                ->setDate(time())
                ->setBody("Parting is such sweet sorrow.");
            $headers = $message->getHeaders();
            $headers->addTextHeader('X-Freegle-Mail-Type', 'Removed');
            $this->sendIt($mailer, $message);
        }
        // @codeCoverageIgnoreEnd

        # Trigger removal of any Yahoo memberships.
        $sql = "SELECT email FROM users_emails LEFT JOIN memberships_yahoo ON users_emails.id = memberships_yahoo.emailid INNER JOIN memberships ON memberships_yahoo.membershipid = memberships.id AND memberships.groupid = ? WHERE users_emails.userid = ? AND memberships_yahoo.role = 'Member';";
        $emails = $this->dbhr->preQuery($sql, [$groupid, $this->id]);
        #error_log("$sql, $groupid, {$this->id}");

        foreach ($emails as $email) {
            #error_log("Remove #$groupid {$email['email']}");
            if ($ban) {
                $type = 'BanApprovedMember';
            } else {
                $type = $this->isPendingMember($groupid) ? 'RejectPendingMember' : 'RemoveApprovedMember';
            }

            # It would be odd for them to be on Yahoo with no email but handle it anyway.
            if ($email['email']) {
                if ($g->getPrivate('onyahoo')) {
                    $p = new Plugin($this->dbhr, $this->dbhm);
                    $p->add($groupid, [
                        'type' => $type,
                        'email' => $email['email']
                    ]);

                    if (ourDomain($email['email'])) {
                        # This is an email address we host, so we can email an unsubscribe request.  We do both this and
                        # the plugin work because Yahoo is as flaky as all get out.
                        for ($i = 0; $i < 10; $i++) {
                            list ($transport, $mailer) = getMailer();
                            $message = Swift_Message::newInstance()
                                ->setSubject('Please release me')
                                ->setFrom([$email['email']])
                                ->setTo($g->getGroupUnsubscribe())
                                ->setDate(time())
                                ->setBody('Let me go');
                            $this->sendIt($mailer, $message);
                        }
                    }
                }
            }
        }

        if ($ban) {
            $sql = "INSERT IGNORE INTO users_banned (userid, groupid, byuser) VALUES (?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                $groupid,
                $meid
            ]);
        }

        $l = new Log($this->dbhr, $this->dbhm);
        $l->log([
            'type' => Log::TYPE_GROUP,
            'subtype' => Log::SUBTYPE_LEFT,
            'user' => $this->id,
            'byuser' => $meid,
            'groupid' => $groupid,
            'text' => $spam ? "Autoremoved spammer" : ($ban ? "via ban" : NULL)
        ]);

        # Now remove the membership.
        $rc = $this->dbhm->preExec("DELETE FROM memberships WHERE userid = ? AND groupid = ?;",
            [
                $this->id,
                $groupid
            ]);

        return ($rc);
    }

    public function getMemberships($modonly = FALSE, $grouptype = NULL, $getwork = FALSE, $pernickety = FALSE)
    {
        $ret = [];
        $modq = $modonly ? " AND role IN ('Owner', 'Moderator') " : "";
        $typeq = $grouptype ? (" AND `type` = " . $this->dbhr->quote($grouptype)) : '';
        $publishq = MODTOOLS ? "" : "AND groups.publish = 1";
        $sql = "SELECT memberships.settings, collection, emailfrequency, eventsallowed, volunteeringallowed, groupid, role, configid, ourPostingStatus, CASE WHEN namefull IS NOT NULL THEN namefull ELSE nameshort END AS namedisplay FROM memberships INNER JOIN groups ON groups.id = memberships.groupid $publishq WHERE userid = ? $modq $typeq ORDER BY LOWER(namedisplay) ASC;";
        $groups = $this->dbhr->preQuery($sql, [$this->id]);
        #error_log("getMemberships $sql {$this->id} " . var_export($groups, TRUE));

        $c = new ModConfig($this->dbhr, $this->dbhm);

        foreach ($groups as $group) {
            $g = NULL;
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $one = $g->getPublic();

            $one['role'] = $group['role'];
            $one['collection'] = $group['collection'];
            $amod = ($one['role'] == User::ROLE_MODERATOR || $one['role'] == User::ROLE_OWNER);
            $one['configid'] = presdef('configid', $group, NULL);

            if ($amod && !pres('configid', $one)) {
                # Get a config using defaults.
                $one['configid'] = $c->getForGroup($this->id, $group['groupid']);
            }

            $one['mysettings'] = $this->getGroupSettings($group['groupid'], presdef('configid', $one, NULL));

            # If we don't have our own email on this group we won't be sending mails.  This is what affects what
            # gets shown on the Settings page for the user, and we only want to check this here
            # for performance reasons.
            $one['mysettings']['emailfrequency'] = ($pernickety || $this->sendOurMails($g, FALSE, FALSE)) ? $one['mysettings']['emailfrequency'] : 0;

            if ($getwork) {
                # We only need finding out how much work there is if we are interested in seeing it.
                $active = $this->activeModForGroup($group['groupid'], $one['mysettings']);

                if ($amod && $active) {
                    # Give a summary of outstanding work.
                    $one['work'] = $g->getWorkCounts($one['mysettings'], $this->id);
                }

                # See if there is a membersync pending
                $syncpendings = $this->dbhr->preQuery("SELECT lastupdated, lastprocessed FROM memberships_yahoo_dump WHERE groupid = ? AND (lastprocessed IS NULL OR lastupdated > lastprocessed);", [$group['groupid']]);
                $one['syncpending'] = count($syncpendings) > 0;
            }

            $ret[] = $one;
        }

        return ($ret);
    }

    public function getConfigs()
    {
        $ret = [];
        $me = whoAmI($this->dbhr, $this->dbhm);

        # We can see configs which
        # - we created
        # - are used by mods on groups on which we are a mod
        # - defaults
        $modships = $me ? $this->getModeratorships() : [];
        $modships = count($modships) > 0 ? $modships : [0];

        $sql = "SELECT DISTINCT * FROM ((SELECT configid AS id FROM memberships WHERE groupid IN (" . implode(',', $modships) . ") AND role IN ('Owner', 'Moderator') AND configid IS NOT NULL) UNION (SELECT id FROM mod_configs WHERE createdby = {$this->id} OR `default` = 1)) t;";
        $ids = $this->dbhr->preQuery($sql);

        foreach ($ids as $id) {
            $c = new ModConfig($this->dbhr, $this->dbhm, $id['id']);
            $thisone = $c->getPublic(FALSE);

            if ($me) {
                if ($thisone['createdby'] == $me->getId()) {
                    $thisone['cansee'] = ModConfig::CANSEE_CREATED;
                } else if ($thisone['default']) {
                    $thisone['cansee'] = ModConfig::CANSEE_DEFAULT;
                } else {
                    # Need to find out who shared it
                    $sql = "SELECT userid, groupid FROM memberships WHERE groupid IN (" . implode(',', $modships) . ") AND userid != {$this->id} AND role IN ('Moderator', 'Owner') AND configid = {$id['id']};";
                    $shareds = $this->dbhr->preQuery($sql);

                    foreach ($shareds as $shared) {
                        $thisone['cansee'] = ModConfig::CANSEE_SHARED;
                        $u = User::get($this->dbhr, $this->dbhm, $shared['userid']);
                        $g = Group::get($this->dbhr, $this->dbhm, $shared['groupid']);
                        $ctx = NULL;
                        $thisone['sharedby'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE);
                        $thisone['sharedon'] = $g->getPublic();
                    }
                }
            }

            $u = User::get($this->dbhr, $this->dbhm, $thisone['createdby']);

            if ($u->getId()) {
                $ctx = NULL;
                $thisone['createdby'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE);

                # Remove their email list - which might be long - to save space.
                unset($thisone['createdby']['emails']);
            }

            $ret[] = $thisone;
        }

        # Return in alphabetical order.
        usort($ret, function ($a, $b) {
            return (strcmp(strtolower($a['name']), strtolower($b['name'])));
        });

        return ($ret);
    }

    public function getModeratorships()
    {
        $this->cacheMemberships();

        $ret = [];
        foreach ($this->memberships AS $membership) {
            if ($membership['role'] == 'Owner' || $membership['role'] == 'Moderator') {
                $ret[] = $membership['groupid'];
            }
        }

        return ($ret);
    }

    public function isModOrOwner($groupid)
    {
        # Very frequently used.  Cache in session.
        if (array_key_exists('modorowner', $_SESSION) && array_key_exists($this->id, $_SESSION['modorowner']) && array_key_exists($groupid, $_SESSION['modorowner'][$this->id])) {
            #error_log("{$this->id} group $groupid cached");
            return ($_SESSION['modorowner'][$this->id][$groupid]);
        } else {
            $sql = "SELECT groupid FROM memberships WHERE userid = ? AND role IN ('Moderator', 'Owner') AND groupid = ?;";
            #error_log("$sql {$this->id}, $groupid");
            $groups = $this->dbhr->preQuery($sql, [
                $this->id,
                $groupid
            ]);

            foreach ($groups as $group) {
                $_SESSION['modorowner'][$this->id][$groupid] = TRUE;
                return TRUE;
            }

            $_SESSION['modorowner'][$this->id][$groupid] = FALSE;
            return (FALSE);
        }
    }

    public function getLogins($credentials = TRUE)
    {
        $logins = $this->dbhr->preQuery("SELECT * FROM users_logins WHERE userid = ?;",
            [$this->id]);

        foreach ($logins as &$login) {
            if (!$credentials) {
                unset($login['credentials']);
            }
            $login['added'] = ISODate($login['added']);
            $login['lastaccess'] = ISODate($login['lastaccess']);
            $login['uid'] = '' . $login['uid'];
        }

        return ($logins);
    }

    public function findByLogin($type, $uid)
    {
        $logins = $this->dbhr->preQuery("SELECT * FROM users_logins WHERE uid = ? AND type = ?;",
            [$uid, $type]);

        foreach ($logins as $login) {
            return ($login['userid']);
        }

        return (NULL);
    }

    public function addLogin($type, $uid, $creds = NULL)
    {
        if ($type == User::LOGIN_NATIVE) {
            # Native login - the uid is the password encrypt the password a bit.
            $creds = $this->hashPassword($creds);
            $uid = $this->id;
        }

        # If the login with this type already exists in the table, that's fine.
        $sql = "INSERT INTO users_logins (userid, uid, type, credentials) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE credentials = ?;";
        $rc = $this->dbhm->preExec($sql,
            [$this->id, $uid, $type, $creds, $creds]);

        # If we add a login, we might be about to log in.
        # TODO This is a bit hacky.
        global $sessionPrepared;
        $sessionPrepared = FALSE;

        return ($rc);
    }

    public function removeLogin($type, $uid)
    {
        $rc = $this->dbhm->preExec("DELETE FROM users_logins WHERE userid = ? AND type = ? AND uid = ?;",
            [$this->id, $type, $uid]);
        return ($rc);
    }

    public function getRoleForGroup($groupid, $overrides = TRUE)
    {
        # We can have a number of roles on a group
        # - none, we can only see what is member
        # - member, we are a group member and can see some extra info
        # - moderator, we can see most info on a group
        # - owner, we can see everything
        #
        # If our system role is support then we get moderator status; if it's admin we get owner status.
        $role = User::ROLE_NONMEMBER;

        if ($overrides) {
            switch ($this->getPrivate('systemrole')) {
                case User::SYSTEMROLE_SUPPORT:
                    $role = User::ROLE_MODERATOR;
                    break;
                case User::SYSTEMROLE_ADMIN:
                    $role = User::ROLE_OWNER;
                    break;
            }
        }

        # Now find if we have any membership of the group which might also give us a role.
        $membs = $this->dbhr->preQuery("SELECT role FROM memberships WHERE userid = ? AND groupid = ?;", [
            $this->id,
            $groupid
        ]);

        foreach ($membs as $memb) {
            switch ($memb['role']) {
                case 'Moderator':
                    # Don't downgrade from owner if we have that by virtue of an override.
                    $role = $role == User::ROLE_OWNER ? $role : User::ROLE_MODERATOR;
                    break;
                case 'Owner':
                    $role = User::ROLE_OWNER;
                    break;
                case 'Member':
                    # Don't downgrade if we already have a role by virtue of an override.
                    $role = $role == User::ROLE_NONMEMBER ? User::ROLE_MEMBER : $role;
                    break;
            }
        }

        return ($role);
    }

    public function moderatorForUser($userid)
    {
        # There are times when we want to check whether we can administer a user, but when we are not immediately
        # within the context of a known group.  We can administer a user when:
        # - they're only a user themselves
        # - we are a mod on one of the groups on which they are a member.
        # - it's us
        if ($userid != $this->getId()) {
            $u = User::get($this->dbhr, $this->dbhm, $userid);

            $usermemberships = [];
            $groups = $this->dbhr->preQuery("SELECT groupid FROM memberships WHERE userid = ? AND role IN ('Member');", [$userid]);
            foreach ($groups as $group) {
                $usermemberships[] = $group['groupid'];
            }

            $mymodships = $this->getModeratorships();

            # Is there any group which we mod and which they are a member of?
            $canmod = count(array_intersect($usermemberships, $mymodships)) > 0;
        } else {
            $canmod = TRUE;
        }

        return ($canmod);
    }

    public function getSetting($setting, $default)
    {
        $ret = $default;
        $s = $this->getPrivate('settings');

        if ($s) {
            $settings = json_decode($s, TRUE);
            $ret = array_key_exists($setting, $settings) ? $settings[$setting] : $default;
        }

        return ($ret);
    }

    public function setSetting($setting, $val)
    {
        $s = $this->getPrivate('settings');

        if ($s) {
            $settings = json_decode($s, TRUE);
        } else {
            $settings = [];
        }

        $settings[$setting] = $val;
        $this->setPrivate('settings', json_encode($settings));
    }

    public function setGroupSettings($groupid, $settings)
    {
        $this->clearMembershipCache();
        $sql = "UPDATE memberships SET settings = ? WHERE userid = ? AND groupid = ?;";
        return ($this->dbhm->preExec($sql, [
            json_encode($settings),
            $this->id,
            $groupid
        ]));
    }

    public function activeModForGroup($groupid, $mysettings = NULL)
    {
        $mysettings = $mysettings ? $mysettings : $this->getGroupSettings($groupid);

        # If we have the active flag use that; otherwise assume that the legacy showmessages flag tells us.  Default
        # to active.
        # TODO Retire showmessages entirely and remove from user configs.
        $active = array_key_exists('active', $mysettings) ? $mysettings['active'] : (!array_key_exists('showmessages', $mysettings) || $mysettings['showmessages']);
        return ($active);
    }

    public function getGroupSettings($groupid, $configid = NULL)
    {
        # We have some parameters which may give us some info which saves queries
        $this->cacheMemberships();

        # Defaults match memberships ones in Group.php.
        $defaults = [
            'active' => 1,
            'showchat' => 1,
            'pushnotify' => 1,
            'eventsallowed' => 1,
            'volunteeringallowed' => 1
        ];

        $settings = $defaults;

        if (pres($groupid, $this->memberships)) {
            $set = $this->memberships[$groupid];

            if ($set['settings']) {
                $settings = json_decode($set['settings'], TRUE);

                if (!$configid && ($set['role'] == User::ROLE_OWNER || $set['role'] == User::ROLE_MODERATOR)) {
                    $c = new ModConfig($this->dbhr, $this->dbhm);

                    # We might have an explicit configid - if so, use it to save on DB calls.
                    $settings['configid'] = $set['configid'] ? $set['configid'] : $c->getForGroup($this->id, $groupid);
                }
            }

            # Base active setting on legacy showmessages setting if not present.
            $settings['active'] = array_key_exists('active', $settings) ? $settings['active'] : (!array_key_exists('showmessages', $settings) || $settings['showmessages']);
            $settings['active'] = $settings['active'] ? 1 : 0;

            foreach ($defaults as $key => $val) {
                if (!array_key_exists($key, $settings)) {
                    $settings[$key] = $val;
                }
            }

            $settings['emailfrequency'] = $set['emailfrequency'];
            $settings['eventsallowed'] = $set['eventsallowed'];
            $settings['volunteeringallowed'] = $set['volunteeringallowed'];
        }

        return ($settings);
    }

    public function setRole($role, $groupid)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        Session::clearSessionCache();

        $l = new Log($this->dbhr, $this->dbhm);
        $l->log([
            'type' => Log::TYPE_USER,
            'byuser' => $me ? $me->getId() : NULL,
            'subtype' => Log::SUBTYPE_ROLE_CHANGE,
            'groupid' => $groupid,
            'user' => $this->id,
            'text' => $role
        ]);

        $this->clearMembershipCache();
        $sql = "UPDATE memberships SET role = ? WHERE userid = ? AND groupid = ?;";
        $rc = $this->dbhm->preExec($sql, [
            $role,
            $this->id,
            $groupid
        ]);

        # We might need to update the systemrole.
        #
        # Not the end of the world if this fails.
        $this->updateSystemRole($role);

        return ($rc);
    }

    public function getInfo()
    {
        # Extra user info.
        $ret = [];
        $start = date('Y-m-d', strtotime("90 days ago"));

        $replies = $this->dbhr->preQuery("SELECT COUNT(DISTINCT refmsgid) AS count FROM chat_messages INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid WHERE userid = ? AND date > ? AND refmsgid IS NOT NULL AND chattype = ? AND type = ?;", [
            $this->id,
            $start,
            ChatRoom::TYPE_USER2USER,
            ChatMessage::TYPE_INTERESTED
        ], FALSE, FALSE);

        $ret['replies'] = $replies[0]['count'];

        $counts = $this->dbhr->preQuery("SELECT COUNT(*) AS count, type FROM messages WHERE fromuser = ? AND arrival > ? GROUP BY type;", [
            $this->id,
            $start
        ], FALSE, FALSE);

        foreach ($counts as $count) {
            if ($count['type'] == Message::TYPE_OFFER) {
                $ret['offers'] = $count['count'];
            } else if ($count['type'] == Message::TYPE_WANTED) {
                $ret['wanteds'] = $count['count'];
            }
        }

        $takens = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_outcomes WHERE userid = ? AND timestamp > ? AND outcome = ?;", [
            $this->id,
            $start,
            Message::OUTCOME_TAKEN
        ], FALSE, FALSE);

        $ret['taken'] = $takens[0]['count'];

        $reneges = $this->dbhr->preQuery("SELECT COUNT(DISTINCT(msgid)) AS count FROM messages_reneged WHERE userid = ? AND timestamp > ?;", [
            $this->id,
            $start
        ], FALSE, FALSE);

        $ret['reneged'] = $reneges[0]['count'];

        # Distance away.
        $me = whoAmI($this->dbhr, $this->dbhm);

        if ($me) {
            list ($mylat, $mylng) = $me->getLatLng();
            $ret['milesaway'] = $this->getDistance($mylat, $mylng);
            $ret['publiclocation'] = $this->getPublicLocation();
        }

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $ret['replytime'] = $r->replyTime($this->id);
        $ret['nudges'] = $r->nudgeCount($this->id);

        # Number of items collected.
        $mysqltime = date("Y-m-d", strtotime("90 days ago"));
        $collected = $this->dbhr->preQuery("SELECT COUNT(DISTINCT msgid) AS count FROM messages_outcomes INNER JOIN messages ON messages.id = messages_outcomes.msgid INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id AND chat_messages.type = ? WHERE outcome = ? AND chat_messages.userid = ? AND messages_outcomes.userid = ? AND messages_outcomes.userid != messages.fromuser AND messages.arrival >= '$mysqltime';", [
            ChatMessage::TYPE_INTERESTED,
            Message::OUTCOME_TAKEN,
            $this->id,
            $this->id
        ], FALSE, FALSE);

        $ret['collected'] = $collected[0]['count'];

        $ret['aboutme'] = $this->getAboutMe();

        $ret['ratings'] = $this->getRating();

        return ($ret);
    }

    public function getAboutMe() {
        $ret = NULL;

        $aboutmes = $this->dbhr->preQuery("SELECT * FROM users_aboutme WHERE userid = ? ORDER BY timestamp DESC LIMIT 1;", [
            $this->id
        ]);

        foreach ($aboutmes as $aboutme) {
            $ret = [
                'timestamp' => ISODate($aboutme['timestamp']),
                'text' => $aboutme['text']
            ];
        }

        return($ret);
    }

    private function md5_hex_to_dec($hex_str)
    {
        $arr = str_split($hex_str, 4);
        foreach ($arr as $grp) {
            $dec[] = str_pad(hexdec($grp), 5, '0', STR_PAD_LEFT);
        }
        return floatval("0." . implode('', $dec));
    }

    public function getDistance($mylat, $mylng)
    {
        $p1 = new POI($mylat, $mylng);

        list ($tlat, $tlng) = $this->getLatLng();

        # We need to make sure that we don't reveal the actual location (well, the postcode location) to
        # someone attempting to triangulate.  So first we move the location a bit based on something which
        # can't be known about a user - a hash of their ID and the password salt.
        $tlat += ($this->md5_hex_to_dec(md5(PASSWORD_SALT . $this->id)) - 0.5) / 100;
        $tlng += ($this->md5_hex_to_dec(md5($this->id . PASSWORD_SALT)) - 0.5) / 100;

        # Now randomise the distance a bit each time we get it, so that anyone attempting repeated measurements
        # will get conflicting results around the precise location that isn't actually theirs.  But still close
        # enough to be useful for our purposes.
        $tlat += mt_rand(-500, 500) / 20000;
        $tlng += mt_rand(-500, 500) / 20000;

        $p2 = new POI($tlat, $tlng);
        $metres = $p1->getDistanceInMetersTo($p2);
        $miles = $metres / 1609.344;
        $miles = $miles > 10 ? round($miles) : round($miles, 1);
        return ($miles);
    }

    public function gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array())
    {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

    public function getPublicLocation()
    {
        $loc = NULL;
        $grp = NULL;

        $aid = NULL;
        $lid = NULL;
        $lat = NULL;
        $lng = NULL;

        $s = $this->getPrivate('settings');

        if ($s) {
            $settings = json_decode($s, TRUE);

            if (pres('mylocation', $settings) && pres('area', $settings['mylocation'])) {
                $loc = $settings['mylocation']['area']['name'];
                $lid = $settings['mylocation']['id'];
                $lat = $settings['mylocation']['lat'];
                $lng = $settings['mylocation']['lng'];
            }
        }

        if (!$loc) {
            # Get the name of the last area we used.
            $areas = $this->dbhr->preQuery("SELECT id, name, lat, lng FROM locations WHERE id IN (SELECT areaid FROM locations INNER JOIN users ON users.lastlocation = locations.id WHERE users.id = ?);", [
                $this->id
            ]);

            foreach ($areas as $area) {
                $loc = $area['name'];
                $lid = $area['id'];
                $lat = $area['lat'];
                $lng = $area['lng'];
            }
        }

        if ($lid) {
            # Find the group of which we are a member which is closest to our location.  We do this because generally
            # the number of groups we're in is small and therefore this will be quick, whereas the groupsNear call is
            # fairly slow.
            $sql = "SELECT groups.id, groups.nameshort, groups.namefull FROM groups INNER JOIN memberships ON groups.id = memberships.groupid WHERE memberships.userid = ? AND (poly IS NOT NULL OR polyofficial IS NOT NULL) AND onmap = 1 ORDER BY ST_distance(POINT(?, ?), GeomFromText(CASE WHEN poly IS NULL THEN polyofficial ELSE poly END)) ASC LIMIT 1;";
            $groups = $this->dbhr->preQuery($sql, [
                $this->id,
                $lng,
                $lat
            ]);

            if (count($groups) > 0) {
                $grp = $groups[0]['namefull'] ? $groups[0]['namefull'] : $groups[0]['nameshort'];

                # The location name might be in the group name, in which case just use the group.
                $loc = strpos($grp, $loc) !== FALSE ? NULL : $loc;
            }
        } else {
            # We don't have a location.  All we might have is a membership.
            $sql = "SELECT groups.id, groups.nameshort, groups.namefull FROM groups INNER JOIN memberships ON groups.id = memberships.groupid WHERE memberships.userid = ? ORDER BY added DESC LIMIT 1;";
            $groups = $this->dbhr->preQuery($sql, [
                $this->id,
            ]);

            if (count($groups) > 0) {
                $grp = $groups[0]['namefull'] ? $groups[0]['namefull'] : $groups[0]['nameshort'];
            }
        }

        $display = $loc ? ($loc . ($grp ? ", $grp" : "")) : ($grp ? $grp : '');

        return ([
            'display' => $display,
            'location' => $loc,
            'groupname' => $grp
        ]);
    }

    public function ensureAvatar(&$atts)
    {
        # This involves querying external sites, so we need to use it with care, otherwise we can hang our
        # system.  It can also cause updates, so if we call it lots of times, it can result in cluster issues.
        $forcedefault = FALSE;
        $s = $this->getPrivate('settings');

        if ($s) {
            $settings = json_decode($s, TRUE);
            if (array_key_exists('useprofile', $settings) && !$settings['useprofile']) {
                $forcedefault = TRUE;
            }
        }

        if (!$forcedefault && $atts['profile']['default']) {
            # See if we can do better than a default.
            $emails = $this->getEmails();

            foreach ($emails as $email) {
                if (stripos($email['email'], 'gmail') || stripos($email['email'], 'googlemail')) {
                    # We can try to find profiles for gmail users.
                    $json = @file_get_contents("http://picasaweb.google.com/data/entry/api/user/{$email['email']}?alt=json");
                    $j = json_decode($json, TRUE);

                    if ($j && pres('entry', $j) && pres('gphoto$thumbnail', $j['entry']) && pres('$t', $j['entry']['gphoto$thumbnail'])) {
                        $atts['profile'] = [
                            'url' => $j['entry']['gphoto$thumbnail']['$t'],
                            'turl' => $j['entry']['gphoto$thumbnail']['$t'],
                            'default' => FALSE,
                            'google' => TRUE
                        ];

                        break;
                    }
                } else if (preg_match('/(.*)-g.*@user.trashnothing.com/', $email['email'], $matches)) {
                    # TrashNothing has an API we can use.
                    $url = "https://trashnothing.com/api/users/{$matches[1]}/profile-image?default=" . urlencode('https://' . USER_SITE . '/images/defaultprofile.png');
                    $atts['profile'] = [
                        'url' => $url,
                        'turl' => $url,
                        'default' => FALSE,
                        'TN' => TRUE
                    ];
                } else if (!ourDomain($email['email'])) {
                    # Try for gravatar
                    $gurl = $this->gravatar($email['email'], 200, 404);
                    $g = @file_get_contents($gurl);

                    if ($g) {
                        $atts['profile'] = [
                            'url' => $gurl,
                            'turl' => $this->gravatar($email['email'], 100, 404),
                            'default' => FALSE,
                            'gravatar' => TRUE
                        ];

                        break;
                    }
                }
            }

            if ($atts['profile']['default']) {
                # Try for Facebook.
                $logins = $this->getLogins(TRUE);
                foreach ($logins as $login) {
                    if ($login['type'] == User::LOGIN_FACEBOOK) {
                        if (presdef('useprofile', $atts['settings'], TRUE)) {
                            $atts['profile'] = [
                                'url' => "https://graph.facebook.com/{$login['uid']}/picture",
                                'turl' => "https://graph.facebook.com/{$login['uid']}/picture",
                                'default' => FALSE,
                                'facebook' => TRUE
                            ];
                        }
                    }
                }
            }

            $hash = NULL;

            if (!$atts['profile']['default']) {
                # We think we have a profile.  Make sure we can fetch it and filter out other people's
                # default images.
                $atts['profile']['default'] = TRUE;
                $this->filterDefault($atts['profile'], $hash);
            }

            if ($atts['profile']['default']) {
                # Nothing - so get gravatar to generate a default for us.
                $atts['profile'] = [
                    'url' => $this->gravatar($this->getEmailPreferred(), 200, 'identicon'),
                    'turl' => $this->gravatar($this->getEmailPreferred(), 100, 'identicon'),
                    'default' => FALSE,
                    'gravatar' => TRUE
                ];
            }

            # Save for next time.
            $this->dbhm->preExec("INSERT INTO users_images (userid, url, `default`, hash) VALUES (?, ?, ?, ?);", [
                $this->id,
                $atts['profile']['default'] ? NULL : $atts['profile']['url'],
                $atts['profile']['default'],
                $hash
            ]);
        }
    }

    public function filterDefault(&$profile, &$hash) {
        $hasher = new ImageHash;
        $data = $profile['url'] && strlen($profile['url']) ? file_get_contents($profile['url']) : NULL;
        $hash = NULL;

        if ($data) {
            $img = @imagecreatefromstring($data);

            if ($img) {
                $hash = $hasher->hash($img);
                $profile['default'] = FALSE;
            }
        }

        if ($hash == 'e070716060607120' || $hash == 'd0f0323171707030' || $hash == '13130f4e0e0e4e52' ||
            $hash == '1f0fcf9f9f9fcfff' || $hash == '23230f0c0e0e0c24' || $hash == 'c0c0e070e0603100' ||
            $hash == 'f0f0316870f07130' || $hash == '242e070e060b0d24') {
            # This is a default profile - replace it with ours.
            $profile['url'] = 'https://' . USER_SITE . '/images/defaultprofile.png';
            $profile['turl'] = 'https://' . USER_SITE . '/images/defaultprofile.png';
            $profile['default'] = TRUE;
            $hash = NULL;
        }
    }

    public function getPublic($groupids = NULL, $history = TRUE, $logs = FALSE, &$ctx = NULL, $comments = TRUE, $memberof = TRUE, $applied = TRUE, $modmailsonly = FALSE, $emailhistory = FALSE, $msgcoll = [MessageCollection::APPROVED], $historyfull = FALSE)
    {
        $atts = parent::getPublic();

        $atts['settings'] = presdef('settings', $atts, NULL) ? json_decode($atts['settings'], TRUE) : ['dummy' => TRUE];
        $atts['settings']['notificationmails'] = array_key_exists('notificationmails', $atts['settings']) ? $atts['settings']['notificationmails'] : TRUE;
        $atts['settings']['modnotifs'] = array_key_exists('modnotifs', $atts['settings']) ? $atts['settings']['modnotifs'] : 4;
        $atts['settings']['backupmodnotifs'] = array_key_exists('backupmodnotifs', $atts['settings']) ? $atts['settings']['backupmodnotifs'] : 12;

        $me = whoAmI($this->dbhr, $this->dbhm);
        $systemrole = $me ? $me->getPrivate('systemrole') : User::SYSTEMROLE_USER;
        $myid = $me ? $me->getId() : NULL;
        $freeglemod = $me && $me->isFreegleMod();

        if ($this->id &&
            (($this->getName() == 'A freegler') ||
                (strlen($atts['fullname']) == 32 && $atts['fullname'] == $atts['yahooid'] && preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $atts['fullname'])))) {
            # We have some names derived from Yahoo IDs which are hex strings.  They look silly.  Replace them with
            # something better.  Ditto "A freegler", which is a legacy way in which names were anonymised.
            $email = $this->inventEmail();
            $atts['fullname'] = substr($email, 0, strpos($email, '-'));
            $this->setPrivate('fullname', $atts['fullname']);
        }

        $atts['displayname'] = $this->getName();

        $atts['added'] = ISODate($atts['added']);

        foreach (['fullname', 'firstname', 'lastname'] as $att) {
            # Make sure we don't return an email if somehow one has snuck in.
            $atts[$att] = strpos($atts[$att], '@') !== FALSE ? substr($atts[$att], 0, strpos($atts[$att], '@')) : $atts[$att];
        }

        # Get a profile.  This function is called so frequently that we can't afford to query external sites
        # within it, so if we don't find one, we default to none.
        $atts['profile'] = [
            'url' => 'https://' . USER_SITE . '/images/defaultprofile.png',
            'turl' => 'https://' . USER_SITE . '/images/defaultprofile.png',
            'default' => TRUE
        ];

        $emails = NULL;

        if (gettype($atts['settings']) == 'array' &&
            (!array_key_exists('useprofile', $atts['settings']) || $atts['settings']['useprofile']) &&
            ($this->profile) &&
            (!$this->profile['default'])) {
            # Return the profile
            $atts['profile'] = $this->profile;
        }

        if ($me && $this->id == $me->getId()) {
            # Add in private attributes for our own entry.
            $emails = $emails ? $emails : $me->getEmails();
            $atts['emails'] = $emails;
            $atts['email'] = $me->getEmailPreferred();
            $atts['relevantallowed'] = $me->getPrivate('relevantallowed');
            $atts['permissions'] = $me->getPrivate('permissions');
        }

        if ($me && ($me->isModerator() || $this->id == $me->getId())) {
            # Mods can see email settings, no matter which group.
            $atts['onholidaytill'] = $this->user['onholidaytill'] ? ISODate($this->user['onholidaytill']) : NULL;
        } else {
            # Don't show some attributes unless they're a mod or ourselves.
            $showmod = $this->isModerator() && presdef('showmod', $atts['settings'], FALSE);
            $atts['settings'] = ['showmod' => $showmod];
            $atts['yahooid'] = NULL;
            $atts['yahooUserId'] = NULL;
        }

        # Some info is only relevant for ModTools, rather than the user site.
        if (MODTOOLS) {
            if ($history) {
                # Add in the message history - from any of the emails associated with this user.
                #
                # We want one entry in here for each repost, so we LEFT JOIN with the reposts table.
                $atts['messagehistory'] = [];
                $sql = NULL;
                $collq = count($msgcoll) ? (" AND messages_groups.collection IN ('" . implode("','", $msgcoll) . "') ") : '';
                $earliest = $historyfull ? '1970-01-01' : date('Y-m-d', strtotime("midnight 30 days ago"));

                if ($groupids && count($groupids) > 0) {
                    # On these groups
                    $groupq = implode(',', $groupids);
                    $sql = "SELECT messages.id, messages.fromaddr, messages.arrival, messages.date, messages_groups.collection, messages_postings.date AS repostdate, messages_postings.repost, messages_postings.autorepost, messages.subject, messages.type, DATEDIFF(NOW(), messages.date) AS daysago, messages_groups.groupid FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid AND groupid IN ($groupq) $collq AND fromuser = ? AND messages_groups.deleted = 0 LEFT JOIN messages_postings ON messages.id = messages_postings.msgid WHERE messages.arrival > ? ORDER BY messages.arrival DESC;";
                } else if ($systemrole == User::SYSTEMROLE_SUPPORT || $systemrole == User::SYSTEMROLE_ADMIN) {
                    # We can see all groups.
                    $sql = "SELECT messages.id, messages.fromaddr, messages.arrival, messages.date, messages_groups.collection, messages_postings.date AS repostdate, messages_postings.repost, messages_postings.autorepost, messages.subject, messages.type, DATEDIFF(NOW(), messages.date) AS daysago, messages_groups.groupid FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid $collq AND fromuser = ? AND messages_groups.deleted = 0 LEFT JOIN messages_postings ON messages.id = messages_postings.msgid WHERE messages.arrival > ? ORDER BY messages.arrival DESC;";
                }

                if ($sql) {
                    $atts['messagehistory'] = $this->dbhr->preQuery($sql, [
                        $this->id,
                        $earliest
                    ]);

                    foreach ($atts['messagehistory'] as &$hist) {
                        $hist['arrival'] = pres('repostdate', $hist) ? ISODate($hist['repostdate']) : ISODate($hist['arrival']);
                        $hist['date'] = ISODate($hist['date']);
                    }
                }
            }

            # Add in a count of recent "modmail" type logs which a mod might care about.
            $modships = $me ? $me->getModeratorships() : [];
            $modships = count($modships) == 0 ? [0] : $modships;
            $sql = "SELECT COUNT(*) AS count FROM `users_modmails` WHERE userid = ? AND groupid IN (" . implode(',', $modships) . ");";
            #error_log("Find modmails $sql");
            $modmails = (count($modships) == 0 || $modships == [0]) ? [['count' => 0]] : $this->dbhr->preQuery($sql, [$this->id]);
            $atts['modmails'] = $modmails[0]['count'];

            if ($logs) {
                # Add in the log entries we have for this user.  We exclude some logs of little interest to mods.
                # - creation - either of ourselves or others during syncing.
                # - deletion of users due to syncing
                $me = whoAmI($this->dbhr, $this->dbhm);
                $startq = $ctx ? " AND id < {$ctx['id']} " : '';
                $modmailq = " AND ((type = 'Message' AND subtype IN ('Rejected', 'Deleted', 'Replied')) OR (type = 'User' AND subtype IN ('Mailed', 'Rejected', 'Deleted'))) AND (TEXT IS NULL OR text NOT IN ('Not present on Yahoo','Received later copy of message with same Message-ID')) AND groupid IN (" . implode(',', $modships) . ")";
                $modq = $modmailsonly ? $modmailq : '';
                $sql = "SELECT DISTINCT * FROM logs WHERE (user = ? OR byuser = ?) $startq AND NOT (type = 'User' AND subtype IN('Created', 'Merged', 'YahooConfirmed')) AND (text IS NULL OR text NOT IN ('Not present on Yahoo', 'Sync of whole membership list','Received later copy of message with same Message-ID')) $modq ORDER BY id DESC LIMIT 50;";
                $logs = $this->dbhr->preQuery($sql, [$this->id, $this->id]);
                #error_log($sql . $this->id);
                $atts['logs'] = [];
                $groups = [];
                $users = [];
                $configs = [];

                if (!$ctx) {
                    $ctx = ['id' => 0];
                }

                foreach ($logs as $log) {
                    $ctx['id'] = $ctx['id'] == 0 ? $log['id'] : min($ctx['id'], $log['id']);

                    if (pres('byuser', $log)) {
                        if (!pres($log['byuser'], $users)) {
                            $u = User::get($this->dbhr, $this->dbhm, $log['byuser']);
                            $users[$log['byuser']] = $u->getPublic(NULL, FALSE, FALSE);
                        }

                        $log['byuser'] = $users[$log['byuser']];
                    }

                    if (pres('user', $log)) {
                        if (!pres($log['user'], $users)) {
                            $u = User::get($this->dbhr, $this->dbhm, $log['user']);
                            $users[$log['user']] = $u->getPublic(NULL, FALSE, FALSE);
                        }

                        $log['user'] = $users[$log['user']];
                    }

                    if (pres('groupid', $log)) {
                        if (!pres($log['groupid'], $groups)) {
                            $g = Group::get($this->dbhr, $this->dbhm, $log['groupid']);

                            if ($g->getId()) {
                                $groups[$log['groupid']] = $g->getPublic();
                                $groups[$log['groupid']]['myrole'] = $me ? $me->getRoleForGroup($log['groupid']) : User::ROLE_NONMEMBER;
                            }
                        }

                        # We can see logs for ourselves.
                        if (!($myid != NULL && pres('user', $log) && presdef('id', $log['user'], NULL) == $myid) &&
                            $g->getId() &&
                            $groups[$log['groupid']]['myrole'] != User::ROLE_OWNER &&
                            $groups[$log['groupid']]['myrole'] != User::ROLE_MODERATOR
                        ) {
                            # We can only see logs for this group if we have a mod role, or if we have appropriate system
                            # rights.  Skip this log.
                            continue;
                        }

                        $log['group'] = presdef($log['groupid'], $groups, NULL);
                    }

                    if (pres('configid', $log)) {
                        if (!pres($log['configid'], $configs)) {
                            $c = new ModConfig($this->dbhr, $this->dbhm, $log['configid']);

                            if ($c->getId()) {
                                $configs[$log['configid']] = $c->getPublic();
                            }
                        }

                        if (pres($log['configid'], $configs)) {
                            $log['config'] = $configs[$log['configid']];
                        }
                    }

                    if (pres('stdmsgid', $log)) {
                        $s = new StdMessage($this->dbhr, $this->dbhm, $log['stdmsgid']);
                        $log['stdmsg'] = $s->getPublic();
                    }

                    if (pres('msgid', $log)) {
                        $m = new Message($this->dbhr, $this->dbhm, $log['msgid']);

                        if ($m->getID()) {
                            $log['message'] = $m->getPublic(FALSE);
                        } else {
                            # The message has been deleted.
                            $log['message'] = [
                                'id' => $log['msgid'],
                                'deleted' => true
                            ];

                            # See if we can find out why.
                            $sql = "SELECT * FROM logs WHERE msgid = ? AND type = 'Message' AND subtype = 'Deleted' ORDER BY id DESC LIMIT 1;";
                            $deletelogs = $this->dbhr->preQuery($sql, [$log['msgid']]);
                            foreach ($deletelogs as $deletelog) {
                                $log['message']['deletereason'] = $deletelog['text'];
                            }
                        }

                        # Prune large attributes.
                        unset($log['message']['textbody']);
                        unset($log['message']['htmlbody']);
                        unset($log['message']['message']);
                    }

                    $log['timestamp'] = ISODate($log['timestamp']);

                    $atts['logs'][] = $log;
                }

                # Get merge history
                $ids = [$this->id];
                $merges = [];
                do {
                    $added = FALSE;
                    $sql = "SELECT * FROM logs WHERE type = 'User' AND subtype = 'Merged' AND user IN (" . implode(',', $ids) . ");";
                    $logs = $this->dbhr->preQuery($sql);
                    foreach ($logs as $log) {
                        #error_log("Consider merge log {$log['text']}");
                        if (preg_match('/Merged (.*) into (.*?) \((.*)\)/', $log['text'], $matches)) {
                            #error_log("Matched " . var_export($matches, TRUE));
                            #error_log("Check ids {$matches[1]} and {$matches[2]}");
                            foreach ([$matches[1], $matches[2]] as $id) {
                                if (!in_array($id, $ids, TRUE)) {
                                    $added = TRUE;
                                    $ids[] = $id;
                                    $merges[] = ['timestamp' => ISODate($log['timestamp']), 'from' => $matches[1], 'to' => $matches[2], 'reason' => $matches[3]];
                                }
                            }
                        }
                    }
                } while ($added);

                $atts['merges'] = $merges;
            }

            if ($comments) {
                $atts['comments'] = $this->getComments();
            }

            if ($this->user['suspectcount'] > 0) {
                # This user is flagged as suspicious.  The memberships are visible iff the currently logged in user
                # - has a system role which allows it
                # - is a mod on a group which this user is also on.
                $visible = $systemrole == User::SYSTEMROLE_ADMIN || $systemrole == User::SYSTEMROLE_SUPPORT;
                $memberof = [];

                # Check the groups.  The collection that's relevant here is the Yahoo one if present; this is to handle
                # the case where you have two emails and one is approved and the other pending.
                #
                # For groups which have moved from Yahoo we might have multiple entries in memberships_yahoo.  We
                # don't want this to manifest as multiple memberships on the group once it's native.  So we do
                # a union of two queries - one for groups on Yahoo and one not.
                $sql = "SELECT memberships.*,CASE WHEN memberships_yahoo.collection IS NOT NULL THEN memberships_yahoo.collection ELSE memberships.collection END AS coll, 
memberships_yahoo.emailid, memberships_yahoo.added AS yadded, 
groups.onyahoo, groups.onhere, groups.nameshort, groups.namefull, groups.type FROM memberships 
LEFT JOIN memberships_yahoo ON memberships.id = memberships_yahoo.membershipid INNER JOIN groups ON memberships.groupid = groups.id WHERE userid = ? AND onyahoo = 1
UNION
SELECT memberships.*, memberships.collection AS coll,
NULL AS emailid, NULL AS yadded, 
groups.onyahoo, groups.onhere, groups.nameshort, groups.namefull, groups.type FROM memberships 
INNER JOIN groups ON memberships.groupid = groups.id WHERE userid = ? AND onyahoo = 0
;";
                $groups = $this->dbhr->preQuery($sql, [$this->id, $this->id]);

                foreach ($groups as $group) {
                    $role = $me ? $me->getRoleForGroup($group['groupid']) : User::ROLE_NONMEMBER;
                    $name = $group['namefull'] ? $group['namefull'] : $group['nameshort'];

                    $thisone = [
                        'id' => $group['groupid'],
                        'membershipid' => $group['id'],
                        'namedisplay' => $name,
                        'nameshort' => $group['nameshort'],
                        'added' => ISODate(pres('yadded', $group) ? $group['yadded'] : $group['added']),
                        'collection' => $group['coll'],
                        'role' => $group['role'],
                        'emailid' => $group['emailid'] ? $group['emailid'] : $this->getOurEmailId(),
                        'emailfrequency' => $group['emailfrequency'],
                        'eventsallowed' => $group['eventsallowed'],
                        'volunteeringallowed' => $group['volunteeringallowed'],
                        'ourPostingStatus' => $group['ourPostingStatus'],
                        'type' => $group['type'],
                        'onyahoo' => $group['onyahoo'],
                        'onhere' => $group['onhere']
                    ];

                    $memberof[] = $thisone;

                    # We can see this membership if we're a mod on the group, or we're a mod on a Freegle group
                    # and this is one.
                    if ($role == User::ROLE_OWNER || $role == User::ROLE_MODERATOR ||
                        ($group['type'] == Group::GROUP_FREEGLE && $freeglemod)) {
                        $visible = TRUE;
                    }
                }

                if ($visible) {
                    $atts['suspectcount'] = $this->user['suspectcount'];
                    $atts['suspectreason'] = $this->user['suspectreason'];
                    $atts['memberof'] = $memberof;
                }
            }

            $box = NULL;

            if ($memberof && !array_key_exists('memberof', $atts) &&
                ($systemrole == User::ROLE_MODERATOR || $systemrole == User::SYSTEMROLE_ADMIN || $systemrole == User::SYSTEMROLE_SUPPORT)
            ) {
                # We haven't provided the complete list; get the recent ones (which preserves some privacy for the user but
                # allows us to spot abuse) and any which are on our groups.
                $addmax = ($systemrole == User::SYSTEMROLE_ADMIN || $systemrole == User::SYSTEMROLE_SUPPORT) ? PHP_INT_MAX : 31;
                $modids = array_merge([0], $me->getModeratorships());
                $freegleq = $freeglemod ? " OR groups.type = 'Freegle' " : '';
                $sql = "SELECT DISTINCT memberships.*, CASE WHEN memberships_yahoo.collection IS NOT NULL THEN memberships_yahoo.collection ELSE memberships.collection END AS coll, 
memberships_yahoo.emailid, memberships_yahoo.added AS yadded, 
groups.onyahoo, groups.onhere, groups.nameshort, groups.namefull, groups.lat, groups.lng, groups.type FROM memberships LEFT JOIN memberships_yahoo ON memberships.id = memberships_yahoo.membershipid INNER JOIN groups ON memberships.groupid = groups.id WHERE userid = ? AND (DATEDIFF(NOW(), memberships.added) <= $addmax OR memberships.groupid IN (" . implode(',', $modids) . ") $freegleq) AND onyahoo = 1 
UNION
SELECT DISTINCT memberships.*, memberships.collection AS coll, 
NULL AS emailid, NULL AS yadded, 
groups.onyahoo, groups.onhere, groups.nameshort, groups.namefull, groups.lat, groups.lng, groups.type FROM memberships INNER JOIN groups ON memberships.groupid = groups.id WHERE userid = ? AND (DATEDIFF(NOW(), memberships.added) <= $addmax OR memberships.groupid IN (" . implode(',', $modids) . ") $freegleq) AND onyahoo = 0
;";
                $groups = $this->dbhr->preQuery($sql, [$this->id, $this->id]);
                #error_log("Get groups $sql, {$this->id}");
                $memberof = [];

                foreach ($groups as $group) {
                    $name = $group['namefull'] ? $group['namefull'] : $group['nameshort'];

                    $memberof[] = [
                        'id' => $group['groupid'],
                        'membershipid' => $group['id'],
                        'namedisplay' => $name,
                        'nameshort' => $group['nameshort'],
                        'added' => ISODate(pres('yadded', $group) ? $group['yadded'] : $group['added']),
                        'collection' => $group['coll'],
                        'role' => $group['role'],
                        'emailid' => $group['emailid'] ? $group['emailid'] : $this->getOurEmailId(),
                        'emailfrequency' => $group['emailfrequency'],
                        'eventsallowed' => $group['eventsallowed'],
                        'volunteeringallowed' => $group['volunteeringallowed'],
                        'ourpostingstatus' => $group['ourPostingStatus'],
                        'type' => $group['type'],
                        'onyahoo' => $group['onyahoo'],
                        'onhere' => $group['onhere']
                    ];

                    if ($group['lat'] && $group['lng']) {
                        $box = [
                            'swlat' => $box == NULL ? $group['lat'] : min($group['lat'], $box['swlat']),
                            'swlng' => $box == NULL ? $group['lng'] : min($group['lng'], $box['swlng']),
                            'nelng' => $box == NULL ? $group['lng'] : max($group['lng'], $box['nelng']),
                            'nelat' => $box == NULL ? $group['lat'] : max($group['lat'], $box['nelat'])
                        ];
                    }
                }

                $atts['memberof'] = $memberof;
            }

            if ($applied &&
                $systemrole == User::ROLE_MODERATOR ||
                $systemrole == User::SYSTEMROLE_ADMIN ||
                $systemrole == User::SYSTEMROLE_SUPPORT
            ) {
                # As well as being a member of a group, they might have joined and left, or applied and been rejected.
                # This is useful info for moderators.  If the user is suspicious then return the complete list; otherwise
                # just the recent ones.
                $groupq = ($groupids && count($groupids) > 0) ? (" AND (DATEDIFF(NOW(), added) <= 31 OR groupid IN (" . implode(',', $groupids) . ")) ") : ' AND DATEDIFF(NOW(), added) <= 31 ';
                $sql = "SELECT DISTINCT memberships_history.*, groups.nameshort, groups.namefull, groups.lat, groups.lng FROM memberships_history INNER JOIN groups ON memberships_history.groupid = groups.id WHERE userid = ? $groupq ORDER BY added DESC;";
                $membs = $this->dbhr->preQuery($sql, [$this->id]);
                foreach ($membs as &$memb) {
                    $name = $memb['namefull'] ? $memb['namefull'] : $memb['nameshort'];
                    $memb['namedisplay'] = $name;
                    $memb['added'] = ISODate($memb['added']);
                    $memb['id'] = $memb['groupid'];
                    unset($memb['groupid']);

                    if ($memb['lat'] && $memb['lng']) {
                        $box = [
                            'swlat' => $box == NULL ? $memb['lat'] : min($memb['lat'], $box['swlat']),
                            'swlng' => $box == NULL ? $memb['lng'] : min($memb['lng'], $box['swlng']),
                            'nelng' => $box == NULL ? $memb['lng'] : max($memb['lng'], $box['nelng']),
                            'nelat' => $box == NULL ? $memb['lat'] : max($memb['lat'], $box['nelat'])
                        ];
                    }
                }

                $atts['applied'] = $membs;
                $atts['activearea'] = $box;
                $atts['activedistance'] = $box ? round(Location::getDistance($box['swlat'], $box['swlng'], $box['nelat'], $box['nelng'])) : NULL;
            }

            if ($systemrole == User::ROLE_MODERATOR ||
                $systemrole == User::SYSTEMROLE_ADMIN ||
                $systemrole == User::SYSTEMROLE_SUPPORT
            ) {
                # Also fetch whether they're on the spammer list.
                if ($this->spammer) {
                    $atts['spammer'] = $this->spammer;
                }
            }

            if ($emailhistory) {
                $emails = $this->dbhr->preQuery("SELECT * FROM logs_emails WHERE userid = ?;", [$this->id]);
                $atts['emailhistory'] = [];
                foreach ($emails as &$email) {
                    $email['timestamp'] = ISODate($email['timestamp']);
                    unset($email['userid']);
                    $atts['emailhistory'][] = $email;
                }
            }
        }

        return ($atts);
    }

    public function getOurEmailId()
    {
        # For groups we host, we need to know our own email for this user so that we can return it as the
        # email used on the group.
        if (!$this->ouremailid) {
            $emails = $this->getEmails();
            foreach ($emails as $thisemail) {
                if (strpos($thisemail['email'], USER_DOMAIN) !== FALSE) {
                    $this->ouremailid = $thisemail['id'];
                }
            }
        }

        return ($this->ouremailid);
    }

    public function isAdmin()
    {
        return ($this->user['systemrole'] == User::SYSTEMROLE_ADMIN);
    }

    public function isAdminOrSupport()
    {
        return ($this->user['systemrole'] == User::SYSTEMROLE_ADMIN || $this->user['systemrole'] == User::SYSTEMROLE_SUPPORT);
    }

    public function isModerator()
    {
        return ($this->user['systemrole'] == User::SYSTEMROLE_ADMIN ||
            $this->user['systemrole'] == User::SYSTEMROLE_SUPPORT ||
            $this->user['systemrole'] == User::SYSTEMROLE_MODERATOR);
    }

    public function systemRoleMax($role1, $role2)
    {
        $role = User::SYSTEMROLE_USER;

        if ($role1 == User::SYSTEMROLE_MODERATOR || $role2 == User::SYSTEMROLE_MODERATOR) {
            $role = User::SYSTEMROLE_MODERATOR;
        }

        if ($role1 == User::SYSTEMROLE_SUPPORT || $role2 == User::SYSTEMROLE_SUPPORT) {
            $role = User::SYSTEMROLE_SUPPORT;
        }

        if ($role1 == User::SYSTEMROLE_ADMIN || $role2 == User::SYSTEMROLE_ADMIN) {
            $role = User::SYSTEMROLE_ADMIN;
        }

        return ($role);
    }

    public function roleMax($role1, $role2)
    {
        $role = User::ROLE_NONMEMBER;

        if ($role1 == User::ROLE_MEMBER || $role2 == User::ROLE_MEMBER) {
            $role = User::ROLE_MEMBER;
        }

        if ($role1 == User::ROLE_MODERATOR || $role2 == User::ROLE_MODERATOR) {
            $role = User::ROLE_MODERATOR;
        }

        if ($role1 == User::ROLE_OWNER || $role2 == User::ROLE_OWNER) {
            $role = User::ROLE_OWNER;
        }

        return ($role);
    }

    public function roleMin($role1, $role2)
    {
        $role = User::ROLE_OWNER;

        if ($role1 == User::ROLE_MODERATOR || $role2 == User::ROLE_MODERATOR) {
            $role = User::ROLE_MODERATOR;
        }

        if ($role1 == User::ROLE_MEMBER || $role2 == User::ROLE_MEMBER) {
            $role = User::ROLE_MEMBER;
        }

        if ($role1 == User::ROLE_NONMEMBER || $role2 == User::ROLE_NONMEMBER) {
            $role = User::ROLE_NONMEMBER;
        }

        return ($role);
    }

    public function merge($id1, $id2, $reason)
    {
        error_log("Merge $id1, $id2, $reason");

        # We might not be able to merge them, if one or the other has the setting to prevent that.
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $ret = FALSE;

        if ($id1 != $id2 && $u1->canMerge() && $u2->canMerge()) {
            #
            # We want to merge two users.  At present we just merge the memberships, comments, emails and logs; we don't try to
            # merge any conflicting settings.
            #
            # Both users might have membership of the same group, including at different levels.
            #
            # A useful query to find foreign key references is of this form:
            #
            # USE information_schema; SELECT * FROM KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'iznik' AND REFERENCED_TABLE_NAME = 'users';
            #
            # We avoid too much use of quoting in preQuery/preExec because quoted numbers can't use a numeric index and therefore
            # perform slowly.
            #error_log("Merge $id2 into $id1");
            $l = new Log($this->dbhr, $this->dbhm);
            $me = whoAmI($this->dbhr, $this->dbhm);

            $rc = $this->dbhm->beginTransaction();
            $rollback = FALSE;

            if ($rc) {
                try {
                    #error_log("Started transaction");
                    $rollback = TRUE;

                    # Merge the top-level memberships
                    $id2membs = $this->dbhr->preQuery("SELECT * FROM memberships WHERE userid = $id2;");
                    foreach ($id2membs as $id2memb) {
                        # Jiggery-pokery with $rc for UT purposes.
                        $rc2 = $rc;
                        #error_log("$id2 member of {$id2memb['groupid']} ");
                        $id1membs = $this->dbhr->preQuery("SELECT * FROM memberships WHERE userid = $id1 AND groupid = {$id2memb['groupid']};");

                        if (count($id1membs) == 0) {
                            # id1 is not already a member.  Just change our id2 membership to id1.
                            #error_log("...$id1 not a member, UPDATE");
                            $rc2 = $this->dbhm->preExec("UPDATE memberships SET userid = $id1 WHERE userid = $id2 AND groupid = {$id2memb['groupid']};");

                            #error_log("Membership UPDATE merge returned $rc2");
                        } else {
                            # id1 is already a member, so we really have to merge.
                            #
                            # Our new membership has the highest role.
                            $id1memb = $id1membs[0];
                            $role = User::roleMax($id1memb['role'], $id2memb['role']);
                            #error_log("...as is $id1, roles {$id1memb['role']} vs {$id2memb['role']} => $role");

                            if ($role != $id1memb['role']) {
                                $rc2 = $this->dbhm->preExec("UPDATE memberships SET role = ? WHERE userid = $id1 AND groupid = {$id2memb['groupid']};", [
                                    $role
                                ]);
                                #error_log("Set role $rc2");
                            }

                            if ($rc2) {
                                #  Our added date should be the older of the two.
                                $date = min(strtotime($id1memb['added']), strtotime($id2memb['added']));
                                $mysqltime = date("Y-m-d H:i:s", $date);
                                $rc2 = $this->dbhm->preExec("UPDATE memberships SET added = ? WHERE userid = $id1 AND groupid = {$id2memb['groupid']};", [
                                    $mysqltime
                                ]);
                                #error_log("Added $rc2");
                            }

                            # There are several attributes we want to take the non-NULL version.
                            foreach (['configid', 'settings', 'heldby'] as $key) {
                                #error_log("Check {$id2memb['groupid']} memb $id2 $key = " . presdef($key, $id2memb, NULL));
                                if ($id2memb[$key]) {
                                    if ($rc2) {
                                        $rc2 = $this->dbhm->preExec("UPDATE memberships SET $key = ? WHERE userid = $id1 AND groupid = {$id2memb['groupid']};", [
                                            $id2memb[$key]
                                        ]);
                                        #error_log("Set att $key = {$id2memb[$key]} $rc2");
                                    }
                                }
                            }
                        }

                        $rc = $rc2 && $rc ? $rc2 : 0;
                    }

                    # Now move any id2 Yahoo memberships over to refer to id1 before we delete it.
                    # This might result in duplicates so we use IGNORE.
                    $id2membs = $this->dbhm->preQuery("SELECT id, groupid FROM memberships WHERE userid = $id2;");
                    #error_log("Memberships for $id2 " . var_export($id2membs, true));
                    foreach ($id2membs as $id2memb) {
                        $rc2 = $rc;
                        #error_log("Yahoo membs $rc2");

                        $id1membs = $this->dbhm->preQuery("SELECT id FROM memberships WHERE userid = ? AND groupid = ?;", [
                            $id1,
                            $id2memb['groupid']
                        ]);

                        #error_log("Memberships for $id1 on {$id2memb['groupid']} " . var_export($id1membs, true));

                        foreach ($id1membs as $id1memb) {
                            $rc2 = $this->dbhm->preExec("UPDATE IGNORE memberships_yahoo SET membershipid = ? WHERE membershipid = ?;", [
                                $id1memb['id'],
                                $id2memb['id']
                            ]);
                            #error_log("$rc2 from UPDATE IGNORE memberships_yahoo SET membershipid = {$id1memb['id']} WHERE membershipid = {$id2memb['id']};");
                        }

                        if ($rc2) {
                            $rc2 = $this->dbhm->preExec("DELETE FROM memberships_yahoo WHERE membershipid = ?;", [
                                $id2memb['id']
                            ]);
                            #error_log("$rc2 from delete {$id2memb['id']}");
                        }

                        if ($rc2) {
                            # Now we just need to delete the id2 one.
                            $rc2 = $this->dbhm->preExec("DELETE FROM memberships WHERE userid = $id2 AND groupid = {$id2memb['groupid']};");
                            #error_log("Deleted old $id2 of {$id2memb['groupid']} $rc2");
                        }

                        $rc = $rc2 && $rc ? $rc2 : 0;
                    }

                    # Merge the emails.  Both might have a primary address; if so then id1 wins.
                    # There is a unique index, so there can't be a conflict on email.
                    if ($rc) {
                        $primary = NULL;
                        $foundprim = FALSE;
                        $sql = "SELECT * FROM users_emails WHERE userid = $id2 AND preferred = 1;";
                        $emails = $this->dbhr->preQuery($sql);
                        foreach ($emails as $email) {
                            $primary = $email['id'];
                            $foundprim = TRUE;
                        }

                        $sql = "SELECT * FROM users_emails WHERE userid = $id1 AND preferred = 1;";
                        $emails = $this->dbhr->preQuery($sql);
                        foreach ($emails as $email) {
                            $primary = $email['id'];
                            $foundprim = TRUE;
                        }

                        if (!$foundprim) {
                            # No primary.  Whatever we would choose for id1 should become the new one.
                            $pemail = $u1->getEmailPreferred();
                            $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email LIKE ?;", [
                                $pemail
                            ]);

                            foreach ($emails as $email) {
                                $primary = $email['id'];
                            }
                        }

                        #error_log("Merge emails");
                        $sql = "UPDATE users_emails SET userid = $id1, preferred = 0 WHERE userid = $id2;";
                        $rc = $this->dbhm->preExec($sql);

                        if ($primary) {
                            $sql = "UPDATE users_emails SET preferred = 1 WHERE id = $primary;";
                            $rc = $this->dbhm->preExec($sql);
                        }

                        #error_log("Emails now " . var_export($this->dbhm->preQuery("SELECT * FROM users_emails WHERE userid = $id1;"), true));
                        #error_log("Email merge returned $rc");
                    }

                    if ($rc) {
                        # Merge other foreign keys where success is less important.  For some of these there might already
                        # be entries, so we do an IGNORE.
                        $this->dbhm->preExec("UPDATE locations_excluded SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE chat_roster SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE sessions SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE spam_users SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE spam_users SET byuserid = $id1 WHERE byuserid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_addresses SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_banned SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_banned SET byuser = $id1 WHERE byuser = $id2;");
                        $this->dbhm->preExec("UPDATE users_comments SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE users_comments SET byuserid = $id1 WHERE byuserid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_donations SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_images SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_invitations SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE users_logins SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_nearby SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_notifications SET fromuser = $id1 WHERE fromuser = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_notifications SET touser = $id1 WHERE touser = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_nudges SET fromuser = $id1 WHERE fromuser = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_nudges SET touser = $id1 WHERE touser = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_phones SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_push_notifications SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_requests SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_requests SET completedby = $id1 WHERE completedby = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_searches SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE newsfeed SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE messages_reneged SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_stories SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_stories_likes SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_stories_requested SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_thanks SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE modnotifs SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE teams_members SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_aboutme SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE ratings SET rater = $id1 WHERE rater = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE ratings SET ratee = $id1 WHERE ratee = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE users_replytime SET userid = $id1 WHERE userid = $id2;");

                        # Merge chat rooms.  There might have be two separate rooms already, which means that we need
                        # to make sure that messages from both end up in the same one.
                        $rooms = $this->dbhr->preQuery("SELECT * FROM chat_rooms WHERE (user1 = $id2 OR user2 = $id2) AND chattype IN (?,?);", [
                            ChatRoom::TYPE_USER2MOD,
                            ChatRoom::TYPE_USER2USER
                        ]);

                        foreach ($rooms as $room) {
                            # Now see if there is already a chat room between the destination user and whatever this
                            # one is.
                            switch ($room['chattype']) {
                                case ChatRoom::TYPE_USER2MOD;
                                    $sql = "SELECT id FROM chat_rooms WHERE user1 = $id1 AND groupid = {$room['groupid']};";
                                    break;
                                case ChatRoom::TYPE_USER2USER;
                                    $other = $room['user1'] == $id2 ? $room['user2'] : $room['user1'];
                                    $sql = "SELECT id FROM chat_rooms WHERE (user1 = $id1 AND user2 = $other) OR (user2 = $id1 AND user1 = $other);";
                                    break;
                            }

                            $alreadys = $this->dbhr->preQuery($sql);
                            #error_log("Check room {$room['id']} {$room['user1']} => {$room['user2']} $sql " . count($alreadys));

                            if (count($alreadys) > 0) {
                                # Yes, there already is one.
                                $this->dbhm->preExec("UPDATE chat_messages SET chatid = {$alreadys[0]['id']} WHERE chatid = {$room['id']}");
                            } else {
                                # No, there isn't, so we can update our old one.
                                $sql = $room['user1'] == $id2 ? "UPDATE chat_rooms SET user1 = $id1 WHERE id = {$room['id']};" : "UPDATE chat_rooms SET user2 = $id1 WHERE id = {$room['id']};";
                                $this->dbhm->preExec($sql);
                            }
                        }

                        $this->dbhm->preExec("UPDATE chat_messages SET userid = $id1 WHERE userid = $id2;");
                    }

                    # Merge attributes we want to keep if we have them in id2 but not id1.  Some will have unique
                    # keys, so update to delete them.
                    foreach (['fullname', 'firstname', 'lastname', 'yahooUserId', 'yahooid'] as $att) {
                        $users = $this->dbhm->preQuery("SELECT $att FROM users WHERE id = $id2;");
                        foreach ($users as $user) {
                            $this->dbhm->preExec("UPDATE users SET $att = NULL WHERE id = $id2;");
                            User::clearCache($id1);
                            User::clearCache($id2);

                            if ($att != 'fullname') {
                                $this->dbhm->preExec("UPDATE users SET $att = ? WHERE id = $id1 AND $att IS NULL;", [$user[$att]]);
                            } else if (stripos($user[$att], 'fbuser') === FALSE && stripos($user[$att], '-owner') === FALSE) {
                                # We don't want to overwrite a name with FBUser or a -owner address.
                                $this->dbhm->preExec("UPDATE users SET $att = ? WHERE id = $id1;", [$user[$att]]);
                            }
                        }
                    }

                    # Merge the logs.  There should be logs both about and by each user, so we can use the rc to check success.
                    if ($rc) {
                        $rc = $this->dbhm->preExec("UPDATE logs SET user = $id1 WHERE user = $id2;");

                        #error_log("Log merge 1 returned $rc");
                    }

                    if ($rc) {
                        $rc = $this->dbhm->preExec("UPDATE logs SET byuser = $id1 WHERE byuser = $id2;");

                        #error_log("Log merge 2 returned $rc");
                    }

                    # Merge the fromuser in messages.  There might not be any, and it's not the end of the world
                    # if this info isn't correct, so ignore the rc.
                    #error_log("Merge messages, current rc $rc");
                    if ($rc) {
                        $this->dbhm->preExec("UPDATE messages SET fromuser = $id1 WHERE fromuser = $id2;");
                    }

                    # Merge the history
                    #error_log("Merge history, current rc $rc");
                    if ($rc) {
                        $this->dbhm->preExec("UPDATE messages_history SET fromuser = $id1 WHERE fromuser = $id2;");
                        $this->dbhm->preExec("UPDATE memberships_history SET userid = $id1 WHERE userid = $id2;");
                    }

                    # Merge the systemrole.
                    $u1s = $this->dbhr->preQuery("SELECT systemrole FROM users WHERE id = $id1;");
                    foreach ($u1s as $u1) {
                        $u2s = $this->dbhr->preQuery("SELECT systemrole FROM users WHERE id = $id2;");
                        foreach ($u2s as $u2) {
                            $rc = $this->dbhm->preExec("UPDATE users SET systemrole = ? WHERE id = $id1;", [
                                $this->systemRoleMax($u1['systemrole'], $u2['systemrole'])
                            ]);
                        }
                        User::clearCache($id1);
                    }

                    if ($rc) {
                        # Log the merge - before the delete otherwise we will fail to log it.
                        $l->log([
                            'type' => Log::TYPE_USER,
                            'subtype' => Log::SUBTYPE_MERGED,
                            'user' => $id2,
                            'byuser' => $me ? $me->getId() : NULL,
                            'text' => "Merged $id2 into $id1 ($reason)"
                        ]);

                        # Log under both users to make sure we can trace it.
                        $l->log([
                            'type' => Log::TYPE_USER,
                            'subtype' => Log::SUBTYPE_MERGED,
                            'user' => $id1,
                            'byuser' => $me ? $me->getId() : NULL,
                            'text' => "Merged $id2 into $id1 ($reason)"
                        ]);

                        # Finally, delete id2.  Make sure we don't pick up an old cached version, as we've just
                        # changed it quite a bit.
                        #error_log("Delete $id2");
                        error_log("Merged $id1 < $id2, $reason");
                        $deleteme = new User($this->dbhm, $this->dbhm, $id2);
                        $rc = $deleteme->delete(NULL, NULL, NULL, FALSE);
                    }

                    if ($rc) {
                        # Everything worked.
                        $rollback = FALSE;

                        # We might have merged ourself!
                        if (pres('id', $_SESSION) == $id2) {
                            $_SESSION['id'] = $id1;
                        }
                    }
                } catch (Exception $e) {
                    error_log("Merge exception " . $e->getMessage());
                    $rollback = TRUE;
                }
            }

            if ($rollback) {
                # Something went wrong.
                #error_log("Merge failed, rollback");
                $this->dbhm->rollBack();
                $ret = FALSE;
            } else {
                #error_log("Merge worked, commit");
                $ret = $this->dbhm->commit();
            }
        }

        return ($ret);
    }

    # Default mailer is to use the standard PHP one, but this can be overridden in UT.
    private function mailer()
    {
        call_user_func_array('mail', func_get_args());
    }

    private function maybeMail($groupid, $subject, $body, $action)
    {
        if ($body) {
            # We have a mail to send.
            list ($eid, $to) = $this->getEmailForYahooGroup($groupid, FALSE, FALSE);

            # If this is one of our domains, then we should send directly to the preferred email, to avoid
            # the mail coming back to us and getting added into a chat.
            if (!$to || ourDomain($to)) {
                $to = $this->getEmailPreferred();
            }

            if ($to) {
                $g = Group::get($this->dbhr, $this->dbhm, $groupid);
                $atts = $g->getPublic();

                $me = whoAmI($this->dbhr, $this->dbhm);

                # Find who to send it from.  If we have a config to use for this group then it will tell us.
                $name = $me->getName();
                $c = new ModConfig($this->dbhr, $this->dbhm);
                $cid = $c->getForGroup($me->getId(), $groupid);
                $c = new ModConfig($this->dbhr, $this->dbhm, $cid);
                $fromname = $c->getPrivate('fromname');
                $name = ($fromname == 'Groupname Moderator') ? '$groupname Moderator' : $name;

                # We can do a simple substitution in the from name.
                $name = str_replace('$groupname', $atts['namedisplay'], $name);

                $headers = "From: \"$name\" <" . $g->getModsEmail() . ">\r\n";
                $bcc = $c->getBcc($action);

                if ($bcc) {
                    $bcc = str_replace('$groupname', $atts['nameshort'], $bcc);
                    $headers .= "Bcc: $bcc\r\n";
                }

                $this->mailer(
                    $to,
                    $subject,
                    $body,
                    $headers,
                    "-f" . $g->getModsEmail()
                );
            }
        }
    }

    public function mail($groupid, $subject, $body, $stdmsgid, $action = NULL)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_MAILED,
            'user' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'text' => $subject,
            'groupid' => $groupid,
            'stdmsgid' => $stdmsgid
        ]);

        $this->maybeMail($groupid, $subject, $body, $action);
    }

    public function reject($groupid, $subject, $body, $stdmsgid)
    {
        # No need for a transaction - if things go wrong, the member will remain in pending, which is the correct
        # behaviour.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => $subject ? Log::SUBTYPE_REJECTED : Log::SUBTYPE_DELETED,
            'msgid' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'user' => $this->getId(),
            'groupid' => $groupid,
            'text' => $subject,
            'stdmsgid' => $stdmsgid
        ]);

        $sql = "SELECT * FROM memberships WHERE userid = ? AND groupid = ? AND collection = ?;";
        $members = $this->dbhr->preQuery($sql, [$this->id, $groupid, MembershipCollection::PENDING]);
        foreach ($members as $member) {
            if (pres('yahooreject', $member)) {
                # We can trigger rejection by email - do so.
                $this->mailer($member['yahooreject'], "My name is Iznik and I reject this member", "", NULL, '-f' . MODERATOR_EMAIL);
            }

            if (pres('yahooUserId', $this->user)) {
                $sql = "SELECT email FROM users_emails INNER JOIN users ON users_emails.userid = users.id AND users.id = ?;";
                $emails = $this->dbhr->preQuery($sql, [$this->id]);
                $email = count($emails) > 0 ? $emails[0]['email'] : NULL;

                # It would be odd for them to be on Yahoo with no email but handle it anyway.
                if ($email) {
                    $p = new Plugin($this->dbhr, $this->dbhm);
                    $p->add($groupid, [
                        'type' => 'RejectPendingMember',
                        'id' => $this->user['yahooUserId'],
                        'email' => $email
                    ]);
                }
            }
        }

        $this->notif->notifyGroupMods($groupid);

        $this->maybeMail($groupid, $subject, $body, 'Reject Member');

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        if ($g->getSetting('approvemembers', FALSE)) {
            # Let the user know.
            $n = new Notifications($this->dbhr, $this->dbhm);
            $n->add(NULL, $this->id, Notifications::TYPE_MEMBERSHIP_REJECTED, NULL, 'https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort'));
        }

        # We might have messages which are awaiting this membership.  Reject them.
        $msgs = $this->dbhr->preQuery("SELECT messages.id FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser = ? AND groupid = ? AND collection IN (?, ?);", [
            $this->id,
            $groupid,
            MessageCollection::QUEUED_USER,
            MessageCollection::QUEUED_YAHOO_USER
        ], FALSE, FALSE);

        foreach ($msgs as $msg) {
            $this->dbhm->preExec("UPDATE messages_groups SET collection = ? WHERE msgid = ? AND groupid = ?;", [
                MessageCollection::REJECTED,
                $msg['id'],
                $groupid
            ]);
        }

        # Delete from memberships - after emailing, otherwise we won't find the right email for this grup.
        $sql = "DELETE FROM memberships WHERE userid = ? AND groupid = ? AND collection = ?;";
        $this->dbhm->preExec($sql, [$this->id, $groupid, MembershipCollection::PENDING]);
    }

    public function approve($groupid, $subject, $body, $stdmsgid)
    {
        # No need for a transaction - if things go wrong, the member will remain in pending, which is the correct
        # behaviour.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_APPROVED,
            'msgid' => $this->id,
            'user' => $this->getId(),
            'byuser' => $me ? $me->getId() : NULL,
            'groupid' => $groupid,
            'stdmsgid' => $stdmsgid,
            'text' => $subject
        ]);

        $sql = "SELECT memberships_yahoo.* FROM memberships_yahoo INNER JOIN memberships ON memberships_yahoo.membershipid = memberships.id WHERE userid = ? AND groupid = ? AND memberships_yahoo.collection = ?;";
        $members = $this->dbhr->preQuery($sql, [$this->id, $groupid, MembershipCollection::PENDING]);

        foreach ($members as $member) {
            if (pres('yahooapprove', $member)) {
                # We can trigger approval by email - do so.  Yahoo is sluggish so we send multiple times.
                for ($i = 0; $i < 10; $i++) {
                    $this->mailer($member['yahooapprove'], "My name is Iznik and I approve this member", NULL, '-f' . MODERATOR_EMAIL);
                }
            }

            if (pres('yahooUserId', $this->user)) {
                $sql = "SELECT email FROM users_emails INNER JOIN users ON users_emails.userid = users.id AND users.id = ?;";
                $emails = $this->dbhr->preQuery($sql, [$this->id]);
                $email = count($emails) > 0 ? $emails[0]['email'] : NULL;

                # It would be odd for them to be on Yahoo with no email but handle it anyway.
                if ($email) {
                    $p = new Plugin($this->dbhr, $this->dbhm);
                    $p->add($groupid, [
                        'type' => 'ApprovePendingMember',
                        'id' => $this->user['yahooUserId'],
                        'email' => $email
                    ]);
                }
            }
        }

        $sql = "UPDATE memberships SET collection = ? WHERE userid = ? AND groupid = ?;";
        $this->dbhm->preExec($sql, [
            MembershipCollection::APPROVED,
            $this->id,
            $groupid
        ]);

        $this->notif->notifyGroupMods($groupid);

        $this->maybeMail($groupid, $subject, $body, 'Approve Member');

        # Let the user know.
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        if ($g->getSetting('approvemembers', FALSE)) {
            # Let the user know.
            $n = new Notifications($this->dbhr, $this->dbhm);
            $n->add(NULL, $this->id, Notifications::TYPE_MEMBERSHIP_APPROVED, NULL, 'https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort'));
        }

        # We might have messages awaiting this membership.  Move them to pending - we always moderate new members.
        $msgs = $this->dbhr->preQuery("SELECT messages.id FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser = ? AND groupid = ? AND collection = ?;", [
            $this->id,
            $groupid,
            MessageCollection::QUEUED_USER
        ], FALSE, FALSE);

        foreach ($msgs as $msg) {
            $this->dbhm->preExec("UPDATE messages_groups SET collection = ? WHERE msgid = ? AND groupid = ?;", [
                MessageCollection::PENDING,
                $msg['id'],
                $groupid
            ]);
        }
    }

    public function markYahooApproved($groupid, $emailid)
    {
        # Move a member from pending to approved in response to a Yahoo notification mail.
        #
        # Note that we will not always have a pending member application.  For example, suppose we have an
        # existing Yahoo membership with an email address which isn't one of ours; then when we post a message
        # we will trigger an application with one we do host, which will then get confirmed.
        #
        # Perhaps we can get a notification mail for a member not in Pending because their application hasn't been
        # sync'd to us, but this is less of an issue as we will not have work which we are pestering mods to do.
        # We'll pick them up on the next sync or when they post.
        #
        # No need for a transaction - if things go wrong, the member will remain in pending, which is recoverable.
        $emails = $this->dbhr->preQuery("SELECT email FROM users_emails WHERE id = ?;", [$emailid]);
        $email = count($emails) > 0 ? $emails[0]['email'] : NULL;

        $sql = "SELECT * FROM memberships WHERE userid = ? AND groupid = ? AND collection = ?;";
        $members = $this->dbhr->preQuery($sql, [$this->id, $groupid, MembershipCollection::PENDING]);

        foreach ($members as $member) {
            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_APPROVED,
                'msgid' => $this->id,
                'user' => $this->getId(),
                'groupid' => $groupid,
                'text' => "Move from Pending to Approved after Yahoo notification mail for $email"
            ]);

            # Set the membership to be approved.
            $sql = "UPDATE memberships SET collection = ? WHERE userid = ? AND groupid = ?;";
            $this->dbhm->preExec($sql, [
                MembershipCollection::APPROVED,
                $this->id,
                $groupid
            ]);
        }

        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_YAHOO_JOINED,
            'user' => $this->getId(),
            'groupid' => $groupid,
            'text' => $email
        ]);

        # The Yahoo membership should always exist as we'll have created it when we triggered the application, but
        # using REPLACE will fix it if we've deleted it (which we did when fixing a bug, if not otherwise).
        $sql = "REPLACE INTO memberships_yahoo (collection, emailid, membershipid) VALUES (?, ?, (SELECT id FROM memberships WHERE userid = ? AND groupid = ?));";
        $rc = $this->dbhm->preExec($sql, [
            MembershipCollection::APPROVED,
            $emailid,
            $this->id,
            $groupid
        ]);
    }

    function hold($groupid)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        $sql = "UPDATE memberships SET heldby = ? WHERE userid = ? AND groupid = ?;";
        $rc = $this->dbhm->preExec($sql, [$me->getId(), $this->id, $groupid]);

        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_HOLD,
                'user' => $this->id,
                'byuser' => $me ? $me->getId() : NULL
            ]);
        }

        $this->notif->notifyGroupMods($groupid);
    }

    function isHeld($groupid)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        $sql = "SELECT heldby FROM memberships WHERE userid = ? AND groupid = ?;";
        $membs = $this->dbhm->preQuery($sql, [$this->id, $groupid]);
        $ret = NULL;

        foreach ($membs as $memb) {
            $ret = $memb['heldby'];
        }

        return ($ret);
    }

    function release($groupid)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        $sql = "UPDATE memberships SET heldby = NULL WHERE userid = ? AND groupid = ?;";
        $rc = $this->dbhm->preExec($sql, [$this->id, $groupid]);

        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_RELEASE,
                'user' => $this->id,
                'byuser' => $me ? $me->getId() : NULL
            ]);
        }

        $this->notif->notifyGroupMods($groupid);
    }

    public function getComments()
    {
        # We can only see comments on groups on which we have mod status.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $groupids = $me ? $me->getModeratorships() : [];
        $groupids = count($groupids) == 0 ? [0] : $groupids;

        $sql = "SELECT * FROM users_comments WHERE userid = ? AND groupid IN (" . implode(',', $groupids) . ") ORDER BY date DESC;";
        $comments = $this->dbhr->preQuery($sql, [$this->id]);

        foreach ($comments as &$comment) {
            $comment['date'] = ISODate($comment['date']);

            if (pres('byuserid', $comment)) {
                $u = User::get($this->dbhr, $this->dbhm, $comment['byuserid']);

                # Don't ask for comments to stop stack overflow.
                $ctx = NULL;
                $comment['byuser'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE);
            }
        }

        return ($comments);
    }

    public function getComment($id)
    {
        # We can only see comments on groups on which we have mod status.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $groupids = $me ? $me->getModeratorships() : [];
        $groupids = count($groupids) == 0 ? [0] : $groupids;

        $sql = "SELECT * FROM users_comments WHERE id = ? AND groupid IN (" . implode(',', $groupids) . ") ORDER BY date DESC;";
        $comments = $this->dbhr->preQuery($sql, [$id]);

        foreach ($comments as &$comment) {
            $comment['date'] = ISODate($comment['date']);

            if (pres('byuserid', $comment)) {
                $u = User::get($this->dbhr, $this->dbhm, $comment['byuserid']);
                $comment['byuser'] = $u->getPublic();
            }

            return ($comment);
        }

        return (NULL);
    }

    public function addComment($groupid, $user1 = NULL, $user2 = NULL, $user3 = NULL, $user4 = NULL, $user5 = NULL,
                               $user6 = NULL, $user7 = NULL, $user8 = NULL, $user9 = NULL, $user10 = NULL,
                               $user11 = NULL, $byuserid = NULL, $checkperms = TRUE)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        # By any supplied user else logged in user if any.
        $byuserid = $byuserid ? $byuserid : ($me ? $me->getId() : NULL);

        # Can only add comments for a group on which we're a mod.
        $rc = NULL;
        $groups = $checkperms ? ($me ? $me->getModeratorships() : [0]) : [$groupid];
        foreach ($groups as $modgroupid) {
            if ($groupid == $modgroupid) {
                $sql = "INSERT INTO users_comments (userid, groupid, byuserid, user1, user2, user3, user4, user5, user6, user7, user8, user9, user10, user11) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?);";
                $this->dbhm->preExec($sql, [
                    $this->id,
                    $groupid,
                    $byuserid,
                    $user1, $user2, $user3, $user4, $user5, $user6, $user7, $user8, $user9, $user10, $user11
                ]);
                $rc = $this->dbhm->lastInsertId();
            }
        }

        return ($rc);
    }

    public function editComment($id, $user1 = NULL, $user2 = NULL, $user3 = NULL, $user4 = NULL, $user5 = NULL,
                                $user6 = NULL, $user7 = NULL, $user8 = NULL, $user9 = NULL, $user10 = NULL,
                                $user11 = NULL)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        # Update to logged in user if any.
        $byuserid = $me ? $me->getId() : NULL;

        # Can only edit comments for a group on which we're a mod.  This code isn't that efficient but it doesn't
        # happen often.
        $rc = NULL;
        $groups = $me ? $me->getModeratorships() : [0];
        foreach ($groups as $modgroupid) {
            $sql = "SELECT id FROM users_comments WHERE id = ? AND groupid = ?;";
            $comments = $this->dbhr->preQuery($sql, [$id, $modgroupid]);
            foreach ($comments as $comment) {
                $sql = "UPDATE users_comments SET byuserid = ?, user1 = ?, user2 = ?, user3 = ?, user4 = ?, user5 = ?, user6 = ?, user7 = ?, user8 = ?, user9 = ?, user10 = ?, user11 = ? WHERE id = ?;";
                $rc = $this->dbhm->preExec($sql, [
                    $byuserid,
                    $user1, $user2, $user3, $user4, $user5, $user6, $user7, $user8, $user9, $user10, $user11,
                    $comment['id']
                ]);
            }
        }

        return ($rc);
    }

    public function deleteComment($id)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        # Can only delete comments for a group on which we're a mod.
        $rc = FALSE;
        $groups = $me ? $me->getModeratorships() : [];
        foreach ($groups as $modgroupid) {
            $rc = $this->dbhm->preExec("DELETE FROM users_comments WHERE id = ? AND groupid = ?;", [$id, $modgroupid]);
        }

        return ($rc);
    }

    public function deleteComments()
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        # Can only delete comments for a group on which we're a mod.
        $rc = FALSE;
        $groups = $me ? $me->getModeratorships() : [];
        foreach ($groups as $modgroupid) {
            $rc = $this->dbhm->preExec("DELETE FROM users_comments WHERE userid = ? AND groupid = ?;", [$this->id, $modgroupid]);
        }

        return ($rc);
    }

    public function split($email, $name = NULL)
    {
        # We want to ensure that the current user has no reference to these values.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $l = new Log($this->dbhr, $this->dbhm);
        if ($email) {
            $this->removeEmail($email);
        }

        # Reset the Yahoo IDs in case they're wrong.  If they're right they'll get set on the next sync.
        $this->setPrivate('yahooid', NULL);
        $this->setPrivate('yahooUserId', NULL);

        $l->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_SPLIT,
            'user' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'text' => "Split out $email"
        ]);

        $u = new User($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, $name);
        $u->addEmail($email);

        # We might be able to move some messages over.
        $this->dbhm->preExec("UPDATE messages SET fromuser = ? WHERE fromaddr = ?;", [
            $uid2,
            $email
        ]);
        $this->dbhm->preExec("UPDATE messages_history SET fromuser = ? WHERE fromaddr = ?;", [
            $uid2,
            $email
        ]);

        # Chats which reference the messages sent from that email must also be intended for the split user.
        $chats = $this->dbhr->preQuery("SELECT DISTINCT chat_rooms.* FROM chat_rooms INNER JOIN chat_messages ON chat_messages.chatid = chat_rooms.id WHERE refmsgid IN (SELECT id FROM messages WHERE fromaddr = ?);", [
            $email
        ]);

        foreach ($chats as $chat) {
            if ($chat['user1'] == $this->id) {
                $this->dbhm->preExec("UPDATE chat_rooms SET user1 = ? WHERE id = ?;", [
                    $uid2,
                    $chat['id']
                ]);
            }
            if ($chat['user2'] == $this->id) {
                $this->dbhm->preExec("UPDATE chat_rooms SET user2 = ? WHERE id = ?;", [
                    $uid2,
                    $chat['id']
                ]);
            }
        }

        # We might have a name.
        $this->dbhm->preExec("UPDATE users SET fullname = (SELECT fromname FROM messages WHERE fromaddr = ? LIMIT 1) WHERE id = ?;", [
            $email,
            $uid2
        ]);

        # Zap any existing sessions for either.
        $this->dbhm->preExec("DELETE FROM sessions WHERE userid IN (?, ?);", [$this->id, $uid2]);

        # We can't tell which user any existing logins relate to.  So remove them all.  If they log in with native,
        # then they'll have to get a new password.  If they use social login, then it should hook the user up again
        # when they next do.
        $this->dbhm->preExec("DELETE FROM users_logins WHERE userid = ?;", [$this->id]);

        return ($uid2);
    }

    public function welcome($email, $password)
    {
        $loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new Twig_Environment($loader);

        $html = $twig->render('welcome/welcome.html', [
            'email' => $email,
            'password' => $password
        ]);

        $message = Swift_Message::newInstance()
            ->setSubject("Welcome to " . SITE_NAME . "!")
            ->setFrom([NOREPLY_ADDR => SITE_NAME])
            ->setTo($email)
            ->setBody("Thanks for joining" . SITE_NAME . "!" . ($password ? "  Here's your password: $password." : ''));

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        list ($transport, $mailer) = getMailer();
        $this->sendIt($mailer, $message);
    }

    public function forgotPassword($email)
    {
        $link = $this->loginLink(USER_SITE, $this->id, '/settings', User::SRC_FORGOT_PASSWORD, TRUE);
        $html = forgot_password(USER_SITE, USERLOGO, $email, $link);

        $message = Swift_Message::newInstance()
            ->setSubject("Forgot your password?")
            ->setFrom([NOREPLY_ADDR => SITE_NAME])
            ->setTo($email)
            ->setBody("To set a new password, just log in here: $link");

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        list ($transport, $mailer) = getMailer();
        $this->sendIt($mailer, $message);
    }

    public function verifyEmail($email)
    {
        # If this is one of our current emails, then we can just make it the primary.
        $emails = $this->getEmails();
        $handled = FALSE;

        foreach ($emails as $anemail) {
            if ($anemail['email'] == $email) {
                # It's one of ours already; make sure it's flagged as primary.
                $this->addEmail($email, 1);
                $handled = TRUE;
            }
        }

        if (!$handled) {
            # This email is new to this user.  It may or may not currently be in use for another user.  Either
            # way we want to send a verification mail.
            $usersite = strpos($_SERVER['HTTP_HOST'], USER_SITE) !== FALSE;
            $headers = "From: " . SITE_NAME . " <" . NOREPLY_ADDR . ">\nContent-Type: multipart/alternative; boundary=\"_I_Z_N_I_K_\"\nMIME-Version: 1.0";
            $canon = User::canonMail($email);

            do {
                # Loop in case of clash on the key we happen to invent.
                $key = uniqid();
                $sql = "INSERT INTO users_emails (email, canon, validatekey, backwards) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE validatekey = ?;";
                $this->dbhm->preExec($sql,
                    [$email, $canon, $key, strrev($canon), $key]);
            } while (!$this->dbhm->rowsAffected());

            $confirm = $this->loginLink($_SERVER['HTTP_HOST'], $this->id, ($usersite ? "/settings/confirmmail/" : "/modtools/settings/confirmmail/") . urlencode($key), 'changeemail', TRUE);

            list ($transport, $mailer) = getMailer();
            $html = verify_email($email, $confirm, $usersite ? USERLOGO : MODLOGO);

            $message = Swift_Message::newInstance()
                ->setSubject("Please verify your email")
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setReturnPath($this->getBounce())
                ->setTo([$email => $this->getName()])
                ->setBody("Someone, probably you, has said that $email is their email address.\n\nIf this was you, please click on the link below to verify the address; if this wasn't you, please just ignore this mail.\n\n$confirm");

            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
            # Outlook.
            $htmlPart = Swift_MimePart::newInstance();
            $htmlPart->setCharset('utf-8');
            $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
            $htmlPart->setContentType('text/html');
            $htmlPart->setBody($html);
            $message->attach($htmlPart);

            $this->sendIt($mailer, $message);
        }

        return ($handled);
    }

    public function confirmEmail($key)
    {
        $rc = FALSE;
        $sql = "SELECT * FROM users_emails WHERE validatekey = ?;";
        $mails = $this->dbhr->preQuery($sql, [$key]);
        $me = whoAmI($this->dbhr, $this->dbhm);

        foreach ($mails as $mail) {
            if ($mail['userid'] && $mail['userid'] != $me->getId()) {
                # This email belongs to another user.  But we've confirmed that it is ours.  So merge.
                $this->merge($this->id, $mail['userid'], "Verified ownership of email {$mail['email']}");
            }

            $this->dbhm->preExec("UPDATE users_emails SET preferred = 0 WHERE id = ?;", [$this->id]);
            $this->dbhm->preExec("UPDATE users_emails SET userid = ?, preferred = 1, validated = NOW(), validatekey = NULL WHERE id = ?;", [$this->id, $mail['id']]);
            $this->addEmail($mail['email'], 1);
            $rc = TRUE;
        }

        return ($rc);
    }

    public function inventEmail($force = FALSE)
    {
        # An invented email is one on our domain that doesn't give away too much detail, but isn't just a string of
        # numbers (ideally).  We may already have one.
        $email = NULL;

        if (!$force) {
            # We want the most recent of our own emails.
            $emails = $this->getEmails(TRUE);
            foreach ($emails as $thisemail) {
                if (strpos($thisemail['email'], USER_DOMAIN) !== FALSE) {
                    $email = $thisemail['email'];
                    break;
                }
            }
        }

        if (!$email) {
            # If they have a Yahoo ID, that'll do nicely - it's public info.  But some Yahoo IDs are actually
            # email addresses (don't ask) and we don't want those.  And some are stupidly long.
            $yahooid = $this->getPrivate('yahooid');

            if (!$force && $yahooid && strpos($yahooid, '@') === FALSE && strlen($yahooid) <= 16) {
                $email = str_replace(' ', '', $yahooid) . '-' . $this->id . '@' . USER_DOMAIN;
            } else {
                # Their own email might already be of that nature, which would be lovely.
                $personal = [];

                if (!$force) {
                    $email = $this->getEmailPreferred();

                    if ($email) {
                        foreach (['firstname', 'lastname', 'fullname'] as $att) {
                            $words = explode(' ', $this->user[$att]);
                            foreach ($words as $word) {
                                if (stripos($email, $word) !== FALSE) {
                                    # Unfortunately not - it has some personal info in it.
                                    $email = NULL;
                                }
                            }
                        }
                    }
                }

                if ($email) {
                    # We have an email which is fairly anonymous.  Use the LHS.
                    $p = strpos($email, '@');
                    $email = substr($email, 0, $p) . '-' . $this->id . '@' . USER_DOMAIN;
                } else {
                    # We can't make up something similar to their existing email address so invent from scratch.
                    $lengths = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/distinct_word_lengths.json'), true);
                    $bigrams = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/word_start_bigrams.json'), true);
                    $trigrams = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/trigrams.json'), true);

                    do {
                        $length = \Wordle\array_weighted_rand($lengths);
                        $start = \Wordle\array_weighted_rand($bigrams);
                        $email = strtolower(\Wordle\fill_word($start, $length, $trigrams)) . '-' . $this->id . '@' . USER_DOMAIN;

                        # We might just happen to have invented an email with their personal information in it.  This
                        # actually happened in the UT with "test".
                        foreach (['firstname', 'lastname', 'fullname'] as $att) {
                            $words = explode(' ', $this->user[$att]);
                            foreach ($words as $word) {
                                $p = stripos($email, $word);
                                $q = strpos($email, '@');

                                $email = ($p !== FALSE && $p < $q) ? NULL : $email;
                            }
                        }
                    } while (!$email);
                }
            }
        }

        return ($email);
    }

    public function triggerYahooApplication($groupid, $log = TRUE)
    {
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $email = $this->inventEmail();
        $emailid = $this->addEmail($email, 0);
        #error_log("Added email $email id $emailid");

        # We might already have a membership with an email which isn't one of ours.  If so, we don't want to
        # trash that membership by turning it into a pending one.
        $membid = $this->isApprovedMember($groupid);
        if (!$membid) {
            # Set up a pending membership - will be converted to approved when we process the approval notification.
            $this->addMembership($groupid, User::ROLE_MEMBER, $emailid, MembershipCollection::PENDING);
        } else if ($g->onYahoo()) {
            # We are already an approved member on Yahoo, but perhaps not with the right Yahoo ID.
            $yahoos = $this->dbhr->preQuery("SELECT * FROM memberships_yahoo WHERE membershipid = ? AND emailid = ?;", [
                $membid,
                $emailid
            ]);

            if (count($yahoos) == 0) {
                error_log("{$this->id} already a member of $groupid but not with email $email");
                $this->addYahooMembership($membid, User::ROLE_MEMBER, $emailid, MembershipCollection::PENDING);
            }
        }

        $headers = "From: $email>\r\n";

        # Yahoo isn't very reliable, so it's tempting to send the subscribe multiple times.  But this can lead
        # to a situation where we subscribe, the member is rejected on Yahoo, then a later subscribe attempt
        # that was greylisted gets through again.  This annoys mods.  So we only subscribe once, and rely on
        # the cron jobs to retry if this doesn't work.
        list ($transport, $mailer) = getMailer();
        $message = Swift_Message::newInstance()
            ->setSubject("I'm $email, please let me join at " . date(DATE_RSS))
            ->setFrom([$email])
            ->setTo($g->getGroupSubscribe())
            ->setDate(time())
            ->setBody("It's " . date(DATE_RSS) . " and I'd like to join as $email");
        $this->sendIt($mailer, $message);

        if ($log) {
            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_YAHOO_APPLIED,
                'user' => $this->id,
                'groupid' => $groupid,
                'text' => $email
            ]);
        }

        return ($email);
    }

    public function submitYahooQueued($groupid)
    {
        # Get an email address we can use on the group.
        $submitted = 0;
        list ($eid, $email) = $this->getEmailForYahooGroup($groupid, TRUE);
        #error_log("Got email $email for {$this->id} on $groupid, eid $eid");

        if ($email) {
            # We want to send to Yahoo any messages we have not previously sent, as long as they have not had
            # an outcome in the mean time.  Only send recent ones in case we flood the group with old stuff.
            #
            # If we are doing an autorepost we will already have a membership and therefore won't come through here.
            $mysqltime = date("Y-m-d", strtotime("Midnight 7 days ago"));
            $sql = "SELECT messages_groups.msgid FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id LEFT OUTER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id WHERE groupid = ? AND senttoyahoo = 0 AND messages_groups.deleted = 0 AND messages_groups.deleted = 0 AND messages_groups.collection != 'Rejected' AND messages.fromuser = ? AND messages_outcomes.msgid IS NULL AND messages_groups.arrival >= '$mysqltime';";
            $msgs = $this->dbhr->preQuery($sql, [
                $groupid,
                $this->id
            ]);

            foreach ($msgs as $msg) {
                $m = new Message($this->dbhr, $this->dbhm, $msg['msgid']);
                $m->submit($this, $email, $groupid);
                $submitted++;
            }
        }

        return ($submitted);
    }

    public function delete($groupid = NULL, $subject = NULL, $body = NULL, $log = TRUE)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);

        # Delete memberships.  This will remove any Yahoo memberships.
        $membs = $this->getMemberships();
        #error_log("Members in delete " . var_export($membs, TRUE));
        foreach ($membs as $memb) {
            $this->removeMembership($memb['id']);
        }

        $rc = $this->dbhm->preExec("DELETE FROM users WHERE id = ?;", [$this->id]);

        if ($rc && $log) {
            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_DELETED,
                'user' => $this->id,
                'byuser' => $me ? $me->getId() : NULL,
                'text' => $this->getName()
            ]);
        }

        return ($rc);
    }

    public function getUnsubLink($domain, $id, $type = NULL)
    {
        return (User::loginLink($domain, $id, "/unsubscribe/$id", $type));
    }

    public function listUnsubscribe($domain, $id, $type = NULL)
    {
        # Generates the value for the List-Unsubscribe header field.
        $ret = "<mailto:unsubscribe-$id@" . USER_SITE . ">, <" . $this->getUnsubLink($domain, $id, $type) . ">";
        return ($ret);
    }

    public function loginLink($domain, $id, $url = '/', $type = NULL, $auto = FALSE)
    {
        $p = strpos($url, '?');
        $ret = $p === FALSE ? "https://$domain$url?u=$id&src=$type" : "https://$domain$url&u=$id&src=$type";

        if ($auto) {
            # Get a per-user link we can use to log in without a password.
            $key = NULL;
            $sql = "SELECT * FROM users_logins WHERE userid = ? AND type = ?;";
            $logins = $this->dbhr->preQuery($sql, [$id, User::LOGIN_LINK]);
            foreach ($logins as $login) {
                $key = $login['credentials'];
            }

            if (!$key) {
                $key = randstr(32);
                $rc = $this->dbhm->preExec("INSERT INTO users_logins (userid, type, credentials) VALUES (?,?,?);", [
                    $id,
                    User::LOGIN_LINK,
                    $key
                ]);

                # If this didn't work, we still return an URL - worst case they'll have to sign in.
                $key = $rc ? $key : NULL;
            }

            $p = strpos($url, '?');
            $src = $type ? "&src=$type" : "";
            $ret = $p === FALSE ? ("https://$domain$url?u=$id&k=$key$src") : ("https://$domain$url&u=$id&k=$key$src");
        }

        return ($ret);
    }

    public function sendOurMails($g = NULL, $checkholiday = TRUE, $checkbouncing = TRUE)
    {
        $sendit = TRUE;

        if ($g) {
            $groupid = $g->getId();

            #error_log("On Yahoo? " . $g->getPrivate('onyahoo'));

            if ($g->getPrivate('onyahoo')) {
                # We don't want to send out mails to users who are members directly on Yahoo, only
                # for ones which have joined through this platform or its predecessor.
                #
                # We can check this in the Yahoo group membership table to check the email they use
                # for membership.  However it might not be up to date because that relies on mods
                # using ModTools.
                #
                # So if we don't find anything in there, then we check whether this user has any
                # emails which we host.  That tells us whether they've joined any groups via our
                # platform, which tells us whether it's reasonable to send them emails.
                $sendit = FALSE;
                $membershipmail = $this->getEmailForYahooGroup($groupid, TRUE, TRUE)[1];
                #error_log("Membership mail $membershipmail");

                if ($membershipmail) {
                    # They have a membership on Yahoo with one of our addresses.
                    $sendit = TRUE;
                } else {
                    # They don't have a membership on Yahoo with one of our addresses.  If we have sync'd our
                    # membership fairly recently, then we can rely on that and it means that we shouldn't send
                    # it.
                    $lastsync = $g->getPrivate('lastyahoomembersync');
                    $lastsync = $lastsync ? strtotime($lastsync) : NULL;
                    $age = $lastsync ? ((time() - $lastsync) / 3600) : NULL;
                    #error_log("Last sync $age");

                    if (!$age || $age > 7 * 24) {
                        # We don't have a recent sync, because the mods aren't using ModTools regularly.
                        #
                        # Use email for them having any of ours as an approximation.
                        $emails = $this->getEmails();
                        foreach ($emails as $anemail) {
                            if (ourDomain($anemail['email'])) {
                                $sendit = TRUE;
                            }
                        }
                    }
                }
            }
        }

        if ($sendit && $checkholiday) {
            # We might be on holiday.
            $hol = $this->getPrivate('onholidaytill');
            $till = $hol ? strtotime($hol) : 0;
            #error_log("Holiday $till vs " . time());

            $sendit = time() > $till;
        }

        if ($sendit && $checkbouncing) {
            # And don't send if we're bouncing.
            $sendit = !$this->getPrivate('bouncing');
            #error_log("After bouncing $sendit");
        }

        #error_log("Sendit? $sendit");
        return ($sendit);
    }

    public function getMembershipHistory()
    {
        # We get this from our logs.
        $sql = "SELECT * FROM logs WHERE user = ? AND `type` = 'User' ORDER BY id DESC;";
        $logs = $this->dbhr->preQuery($sql, [$this->id]);

        $ret = [];
        foreach ($logs as $log) {
            $thisone = NULL;
            switch ($log['subtype']) {
                case Log::SUBTYPE_JOINED:
                case Log::SUBTYPE_APPROVED:
                case Log::SUBTYPE_REJECTED:
                case Log::SUBTYPE_APPLIED:
                case Log::SUBTYPE_LEFT:
                case Log::SUBTYPE_YAHOO_APPLIED:
                case Log::SUBTYPE_YAHOO_JOINED:
                    {
                        $thisone = $log['subtype'];
                        break;
                    }
            }

            #error_log("{$log['subtype']} gives $thisone {$log['groupid']}");
            if ($thisone && $log['groupid']) {
                $g = Group::get($this->dbhr, $this->dbhm, $log['groupid']);
                $ret[] = [
                    'timestamp' => ISODate($log['timestamp']),
                    'type' => $thisone,
                    'group' => $g->getPublic(),
                    'text' => $log['text']
                ];
            }
        }

        return ($ret);
    }

    public function search($search, $ctx)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $id = presdef('id', $ctx, 0);
        $ctx = $ctx ? $ctx : [];
        $q = $this->dbhr->quote("$search%");
        $backwards = strrev($search);
        $qb = $this->dbhr->quote("$backwards%");

        # If we're searching for a notify address, switch to the user it.
        $search = preg_match('/notify-(.*)-(.*)' . USER_DOMAIN . '/', $search, $matches) ? $matches[2] : $search;

        $sql = "SELECT DISTINCT userid FROM
                ((SELECT userid FROM users_emails WHERE email LIKE $q OR backwards LIKE $qb) UNION
                (SELECT id AS userid FROM users WHERE fullname LIKE $q) UNION
                (SELECT id AS userid FROM users WHERE yahooid LIKE $q) UNION
                (SELECT id AS userid FROM users WHERE id = ?) UNION
                (SELECT userid FROM users_logins WHERE uid LIKE $q) UNION
                (SELECT userid FROM memberships_yahoo INNER JOIN memberships ON memberships_yahoo.membershipid = memberships.id WHERE yahooAlias LIKE $q)) t WHERE userid > ? ORDER BY userid ASC";
        $users = $this->dbhr->preQuery($sql, [$search, $id]);

        $ret = [];
        foreach ($users as $user) {
            $ctx['id'] = $user['userid'];

            $u = User::get($this->dbhr, $this->dbhm, $user['userid']);

            $ctx = NULL;
            $thisone = $u->getPublic(NULL, TRUE, FALSE, $ctx, TRUE, TRUE, TRUE, FALSE, TRUE, [], TRUE);

            # We might not have the emails.
            $thisone['email'] = $u->getEmailPreferred();
            $thisone['emails'] = $u->getEmails();

            # We also want the Yahoo details.  Get them all in a single query for performance.
            $sql = "SELECT DISTINCT memberships.id AS membershipid, memberships_yahoo.* FROM memberships_yahoo INNER JOIN memberships ON memberships.id = memberships_yahoo.membershipid INNER JOIN groups ON groups.id = memberships.groupid WHERE userid = ? AND onyahoo = 1;";
            #error_log("$sql {$user['userid']}");
            $membs = $this->dbhr->preQuery($sql, [$user['userid']]);

            foreach ($thisone['memberof'] as &$member) {
                foreach ($membs as $memb) {
                    if ($memb['membershipid'] == $member['membershipid']) {
                        foreach (['yahooAlias', 'yahooPostingStatus', 'yahooDeliveryType'] as $att) {
                            $member[$att] = $memb[$att];
                        }
                    }
                }
            }

            $thisone['membershiphistory'] = $u->getMembershipHistory();

            # Make sure there's a link login as admin/support can use that to impersonate.
            if ($me->isAdmin() || ($me->isAdminOrSupport() && !$u->isModerator())) {
                $thisone['loginlink'] = $u->loginLink(USER_SITE, $user['userid'], '/', NULL, TRUE);
            }
            $thisone['logins'] = $u->getLogins($me->isAdmin());

            # Also return the chats for this user.
            $r = new ChatRoom($this->dbhr, $this->dbhm);
            $rooms = $r->listForUser($user['userid'], [ChatRoom::TYPE_MOD2MOD, ChatRoom::TYPE_USER2MOD, ChatRoom::TYPE_USER2USER]);
            $thisone['chatrooms'] = [];

            if ($rooms) {
                foreach ($rooms as $room) {
                    $r = new ChatRoom($this->dbhr, $this->dbhm, $room);
                    $thisone['chatrooms'][] = $r->getPublic();
                }
            }

            # Add the public location and best guess lat/lng
            $thisone['publiclocation'] = $u->getPublicLocation();
            $latlng = $u->getLatLng();
            $thisone['privateposition'] = [
                'lat' => $latlng[0],
                'lng' => $latlng[1]
            ];

            $ret[] = $thisone;
        }

        return ($ret);
    }

    public function setPrivate($att, $val)
    {
        User::clearCache($this->id);
        parent::setPrivate($att, $val);
    }

    public function canMerge()
    {
        $settings = pres('settings', $this->user) ? json_decode($this->user['settings'], TRUE) : [];
        return (array_key_exists('canmerge', $settings) ? $settings['canmerge'] : TRUE);
    }

    public function notifsOn($type, $groupid = NULL)
    {
        $settings = pres('settings', $this->user) ? json_decode($this->user['settings'], TRUE) : [];
        $notifs = pres('notifications', $settings);

        $defs = [
            'email' => TRUE,
            'emailmine' => FALSE,
            'push' => TRUE,
            'facebook' => TRUE,
            'app' => TRUE
        ];

        $ret = ($notifs && array_key_exists($type, $notifs)) ? $notifs[$type] : $defs[$type];

        if ($ret && $groupid) {
            # Check we're an active mod on this group - if not then we don't want the notifications.
            $ret = $this->activeModForGroup($groupid);
        }

        #error_log("Notifs on for type $type ? $ret from " . var_export($notifs, TRUE));
        return ($ret);
    }

    public function getNotificationPayload($modtools)
    {
        # This gets a notification count/title/message for this user.
        $total = 0;
        $notifcount = 0;
        $chatcount = 0;
        $title = NULL;
        $message = NULL;
        $chatids = [];
        $route = NULL;

        if (!$modtools) {
            # User notification.  We want to show a count of chat messages, or some of the message if there is just one.
            $r = new ChatRoom($this->dbhr, $this->dbhm);
            $unseen = $r->allUnseenForUser($this->id, [ChatRoom::TYPE_USER2USER, ChatRoom::TYPE_USER2MOD], $modtools);
            $chatcount = count($unseen);
            $total = $chatcount;
            foreach ($unseen as $un) {
                $chatids[] = $un['chatid'];
            };

            #error_log("Chats with unseen " . var_export($chatids, TRUE));
            $n = new Notifications($this->dbhr, $this->dbhm);
            $notifcount = $n->countUnseen($this->id);

            if ($total === 1) {
                $r = new ChatRoom($this->dbhr, $this->dbhm, $unseen[0]['chatid']);
                $atts = $r->getPublic($this);
                $title = $atts['name'];
                list($msgs, $users) = $r->getMessages(100, 0);

                if (count($msgs) > 0) {
                    $message = presdef('message', $msgs[count($msgs) - 1], "You have a message");
                    $message = strlen($message) > 256 ? (substr($message, 0, 256) . "...") : $message;
                }

                $route = "/chat/" . $unseen[0]['chatid'];

                if ($notifcount) {
                    $total += $notifcount;
                }
            } else if ($total > 1) {
                $title = "You have $total new messages";
                $route = "/chats";

                if ($notifcount) {
                    $total += $notifcount;
                    $title .= " and $notifcount notification" . ($notifcount == 1 ? '' : 's');
                }
            } else {
                # Add in the notifications you see primarily from the newsfeed.
                if ($notifcount) {
                    $total += $notifcount;
                    $title = "You have $notifcount notification" . ($notifcount == 1 ? '' : 's');
                    $route = '/';
                }
            }
        } else {
            # ModTools notification.  Similar code in session (to calculate work) and sw.php (to construct notification
            # text on the client side).
            $groups = $this->getMemberships(FALSE, NULL, TRUE);
            $work = [];

            foreach ($groups as &$group) {
                if (pres('work', $group)) {
                    foreach ($group['work'] as $key => $workitem) {
                        if (pres($key, $work)) {
                            $work[$key] += $workitem;
                        } else {
                            $work[$key] = $workitem;
                        }
                    }
                }
            }

            if (pres('pendingmembers', $work) > 0) {
                $title .= $work['pendingmembers'] . ' pending member' . (($work['pendingmembers'] != 1) ? 's' : '') . " \n";
                $total += $work['pendingmembers'];
                $route = 'modtools/members/pending';
            }

            if (pres('pending', $work) > 0) {
                $title .= $work['pending'] . ' pending message' . (($work['pending'] != 1) ? 's' : '') . " \n";
                $total += $work['pending'];
                $route = 'modtools/messages/pending';
            }

            $title = $title == '' ? NULL : $title;
        }


        return ([$total, $chatcount, $notifcount, $title, $message, array_unique($chatids), $route]);
    }

    public function hasPermission($perm)
    {
        $perms = $this->user['permissions'];
        return ($perms && stripos($perms, $perm) !== FALSE);
    }

    public function sendIt($mailer, $message)
    {
        $mailer->send($message);
    }

    public function thankDonation()
    {
        list ($transport, $mailer) = getMailer();
        $message = Swift_Message::newInstance()
            ->setSubject("Thank you for supporting Freegle!")
            ->setFrom(PAYPAL_THANKS_FROM)
            ->setReplyTo(PAYPAL_THANKS_FROM)
            ->setTo($this->getEmailPreferred())
            ->setBody("Thank you for donating to freegle");
        $headers = $message->getHeaders();
        $headers->addTextHeader('X-Freegle-Mail-Type', 'ThankDonation');

        $html = donation_thank($this->getName(), $this->getEmailPreferred(), $this->loginLink(USER_SITE, $this->id, '/?src=thankdonation'), $this->loginLink(USER_SITE, $this->id, '/settings?src=thankdonation'));

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        $this->sendIt($mailer, $message);
    }

    public function invite($email)
    {
        $ret = FALSE;

        # We can only invite logged in.
        if ($this->id) {
            # ...and only if we have spare.
            if ($this->user['invitesleft'] > 0) {
                # They might already be using us - but they might also have forgotten.  So allow that case.  However if
                # they have actively declined a previous invitation we suppress this one.
                $previous = $this->dbhr->preQuery("SELECT id FROM users_invitations WHERE email = ? AND outcome = ?;", [
                    $email,
                    User::INVITE_DECLINED
                ]);

                if (count($previous) == 0) {
                    # The table has a unique key on userid and email, so that means we can only invite the same person
                    # once.  That avoids us pestering them.
                    try {
                        $this->dbhm->preExec("INSERT INTO users_invitations (userid, email) VALUES (?,?);", [
                            $this->id,
                            $email
                        ]);

                        # We're ok to invite.
                        $fromname = $this->getName();
                        $frommail = $this->getEmailPreferred();
                        $url = "https://" . USER_SITE . "/invite/" . $this->dbhm->lastInsertId();

                        list ($transport, $mailer) = getMailer();
                        $message = Swift_Message::newInstance()
                            ->setSubject("$fromname has invited you to try Freegle!")
                            ->setFrom([NOREPLY_ADDR => SITE_NAME])
                            ->setReplyTo($frommail)
                            ->setTo($email)
                            ->setBody("$fromname ($email) thinks you might like Freegle, which helps you give and get things for free near you.  Click $url to try it.");
                        $headers = $message->getHeaders();
                        $headers->addTextHeader('X-Freegle-Mail-Type', 'Invitation');

                        $html = invite($fromname, $frommail, $url);

                        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                        # Outlook.
                        $htmlPart = Swift_MimePart::newInstance();
                        $htmlPart->setCharset('utf-8');
                        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                        $htmlPart->setContentType('text/html');
                        $htmlPart->setBody($html);
                        $message->attach($htmlPart);

                        $this->sendIt($mailer, $message);
                        $ret = TRUE;

                        $this->dbhm->preExec("UPDATE users SET invitesleft = invitesleft - 1 WHERE id = ?;", [
                            $this->id
                        ]);
                    } catch (Exception $e) {
                        # Probably a duplicate.
                    }
                }
            }
        }

        return ($ret);
    }

    public function inviteOutcome($id, $outcome)
    {
        $invites = $this->dbhm->preQuery("SELECT * FROM users_invitations WHERE id = ?;", [
            $id
        ]);

        foreach ($invites as $invite) {
            if ($invite['outcome'] == User::INVITE_PENDING) {
                $this->dbhm->preExec("UPDATE users_invitations SET outcome = ?, outcometimestamp = NOW() WHERE id = ?;", [
                    $outcome,
                    $id
                ]);

                if ($outcome == User::INVITE_ACCEPTED) {
                    # Give the sender two more invites.  This means that if their invitations are unsuccessful, they will
                    # stall, but if they do ok, they won't.  This isn't perfect - someone could fake up emails and do
                    # successful invitations that way.
                    $this->dbhm->preExec("UPDATE users SET invitesleft = invitesleft + 2 WHERE id = ?;", [
                        $invite['userid']
                    ]);
                }
            }
        }
    }

    public function listInvitations()
    {
        $ret = [];

        # Don't show old invitations - unaccepted ones could languish for ages.
        $mysqltime = date('Y-m-d', strtotime("30 days ago"));
        $invites = $this->dbhr->preQuery("SELECT id, email, date, outcome, outcometimestamp FROM users_invitations WHERE userid = ? AND date > '$mysqltime';", [
            $this->id
        ]);

        foreach ($invites as $invite) {
            # Check if this email is now on the platform.
            $invite['date'] = ISODate($invite['date']);
            $invite['outcometimestamp'] = $invite['outcometimestamp'] ? ISODate($invite['outcometimestamp']) : NULL;
            $ret[] = $invite;
        }

        return ($ret);
    }

    public function getLatLng($usedef = TRUE, $usegroup = TRUE)
    {
        $s = $this->getPrivate('settings');
        $lat = NULL;
        $lng = NULL;

        if ($s) {
            $settings = json_decode($s, TRUE);

            if (pres('mylocation', $settings)) {
                $lat = $settings['mylocation']['lat'];
                $lng = $settings['mylocation']['lng'];
            }
        }

        if (!$lat) {
            $lid = $this->getPrivate('lastlocation');

            if ($lid) {
                $l = new Location($this->dbhr, $this->dbhm, $lid);
                $lat = $l->getPrivate('lat');
                $lng = $l->getPrivate('lng');
            }
        }

        if (!$lat && $usegroup) {
            # Try for user groups
            $membs = $this->getMemberships();

            if (count($membs) > 0) {
                $lat = $membs[0]['lat'];
                $lng = $membs[0]['lng'];
            }
        }

        if ($usedef) {
            # ...or failing that, a default.
            $lat = $lat ? $lat : 53.9450;
            $lng = $lng ? $lng : -2.5209;
        }

        return ([$lat, $lng]);
    }

    public function isFreegleMod()
    {
        $ret = FALSE;

        $this->cacheMemberships();

        foreach ($this->memberships as $mem) {
            if ($mem['type'] == Group::GROUP_FREEGLE && ($mem['role'] == User::ROLE_OWNER || $mem['role'] == User::ROLE_MODERATOR)) {
                $ret = TRUE;
            }
        }

        return ($ret);
    }

    public function getKudos($id = NULL)
    {
        $id = $id ? $id : $this->id;
        $kudos = [
            'userid' => $id,
            'posts' => 0,
            'chats' => 0,
            'newsfeed' => 0,
            'events' => 0,
            'vols' => 0,
            'facebook' => 0,
            'platform' => 0,
            'kudos' => 0,
        ];

        $kudi = $this->dbhr->preQuery("SELECT * FROM users_kudos WHERE userid = ?;", [
            $id
        ]);

        foreach ($kudi as $k) {
            $kudos = $k;
        }

        return ($kudos);
    }

    public function updateKudos($id = NULL)
    {
        $current = $this->getKudos($id);

        # Only update if we don't have one or it's older than a day.  This avoids repeatedly updating the entry
        # for the same user in some bulk operations.
        if (!pres('timestamp', $current) || (time() - strtotime($current['timestamp']) > 24 * 60 * 60)) {
            # We analyse a user's activity and assign them a level.
            #
            # Only interested in activity in the last year.
            $id = $id ? $id : $this->id;
            $start = date('Y-m-d', strtotime("365 days ago"));

            # First, the number of months in which they have posted.
            $posts = $this->dbhr->preQuery("SELECT COUNT(DISTINCT(CONCAT(YEAR(date), '-', MONTH(date)))) AS count FROM messages WHERE fromuser = ? AND date >= '$start';", [
                $id
            ])[0]['count'];

            # Ditto communicated with people.
            $chats = $this->dbhr->preQuery("SELECT COUNT(DISTINCT(CONCAT(YEAR(date), '-', MONTH(date)))) AS count FROM chat_messages WHERE userid = ? AND date >= '$start';", [
                $id
            ])[0]['count'];

            # Newsfeed posts
            $newsfeed = $this->dbhr->preQuery("SELECT COUNT(DISTINCT(CONCAT(YEAR(timestamp), '-', MONTH(timestamp)))) AS count FROM newsfeed WHERE userid = ? AND added >= '$start';", [
                $id
            ])[0]['count'];

            # Events
            $events = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM communityevents WHERE userid = ? AND added >= '$start';", [
                $id
            ])[0]['count'];

            # Volunteering
            $vols = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM volunteering WHERE userid = ? AND added >= '$start';", [
                $id
            ])[0]['count'];

            # Do they have a Facebook login?
            $facebook = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM users_logins WHERE userid = ? AND type = ?", [
                    $id,
                    User::LOGIN_FACEBOOK
                ])[0]['count'] > 0;

            # Have they posted using the platform?
            $platform = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM messages WHERE fromuser = ? AND date >= '$start' AND sourceheader = ?;", [
                    $id,
                    Message::PLATFORM
                ])[0]['count'] > 0;

            $kudos = $posts + $chats + $newsfeed + $events + $vols;

            if ($kudos > 0) {
                # No sense in creating entries which are blank or the same.
                $current = $this->getKudos($id);

                if ($current['kudos'] != $kudos) {
                    $this->dbhm->preExec("REPLACE INTO users_kudos (userid, kudos, posts, chats, newsfeed, events, vols, facebook, platform) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);", [
                        $id,
                        $kudos,
                        $posts,
                        $chats,
                        $newsfeed,
                        $events,
                        $vols,
                        $facebook,
                        $platform
                    ], FALSE);
                }
            }
        }
    }

    public function topKudos($gid, $limit = 10)
    {
        $kudos = $this->dbhr->preQuery("SELECT users_kudos.* FROM users_kudos INNER JOIN users ON users.id = users_kudos.userid INNER JOIN memberships ON memberships.userid = users_kudos.userid AND memberships.groupid = ? WHERE memberships.role = ? ORDER BY kudos DESC LIMIT $limit;", [
            $gid,
            User::ROLE_MEMBER
        ]);

        $ret = [];

        foreach ($kudos as $k) {
            $u = new User($this->dbhr, $this->dbhm, $k['userid']);
            $atts = $u->getPublic();
            $atts['email'] = $u->getEmailPreferred();

            $thisone = [
                'user' => $atts,
                'kudos' => $k
            ];

            $ret[] = $thisone;
        }

        return ($ret);
    }

    public function possibleMods($gid, $limit = 10)
    {
        # We look for users who are not mods with top kudos who also:
        # - active in last 60 days
        # - not bouncing
        # - using a location which is in the group area
        # - have posted with the platform, as we don't want loyal users of TN or Yahoo.
        # - have a Facebook login, as they are more likely to do publicity.
        $start = date('Y-m-d', strtotime("60 days ago"));
        $kudos = $this->dbhr->preQuery("SELECT users_kudos.* FROM users_kudos INNER JOIN users ON users.id = users_kudos.userid INNER JOIN memberships ON memberships.userid = users_kudos.userid AND memberships.groupid = ? INNER JOIN groups ON groups.id = memberships.groupid INNER JOIN locations_spatial ON users.lastlocation = locations_spatial.locationid WHERE memberships.role = ? AND users_kudos.platform = 1 AND users_kudos.facebook = 1 AND ST_Contains(GeomFromText(groups.poly), locations_spatial.geometry) AND bouncing = 0 AND lastaccess >= '$start' ORDER BY kudos DESC LIMIT $limit;", [
            $gid,
            User::ROLE_MEMBER
        ]);

        $ret = [];

        foreach ($kudos as $k) {
            $u = new User($this->dbhr, $this->dbhm, $k['userid']);
            $atts = $u->getPublic();
            $atts['email'] = $u->getEmailPreferred();

            $thisone = [
                'user' => $atts,
                'kudos' => $k
            ];

            $ret[] = $thisone;
        }

        return ($ret);
    }

    public function requestExport($sync = FALSE)
    {
        $tag = randstr(64);

        # Flag sync ones as started to avoid window with background thread.
        $sync = $sync ? "NOW()" : "NULL";
        $this->dbhm->preExec("INSERT INTO users_exports (userid, tag, started) VALUES (?, ?, $sync);", [
            $this->id,
            $tag
        ]);

        return ([$this->dbhm->lastInsertId(), $tag]);
    }

    public function export($exportid, $tag)
    {
        $this->dbhm->preExec("UPDATE users_exports SET started = NOW() WHERE id = ? AND tag = ?;", [
            $exportid,
            $tag
        ]);

        # For GDPR we support the ability for a user to export the data we hold about them.  Key points about this:
        #
        # - It needs to be at a high level of abstraction and understandable by the user, not just a cryptic data
        #   dump.
        # - It needs to include data provided by the user and data observed about the user, but not profiling
        #   or categorisation based on that data.  This means that (for example) we need to return which
        #   groups they have joined, but not whether joining those groups has flagged them up as a potential
        #   spammer.
        $ret = [];
        error_log("...basic info");

        # Data in user table.
        $d = [];
        $d['Our_internal_ID_for_you'] = $this->getPrivate('id');
        $d['Your_full_name'] = $this->getPrivate('fullname');
        $d['Your_first_name'] = $this->getPrivate('firstname');
        $d['Your_last_name'] = $this->getPrivate('lastname');
        $d['Yahoo_internal_ID_for_you'] = $this->getPrivate('yahooUserId');
        $d['Your_Yahoo_ID'] = $this->getPrivate('yahooid');
        $d['Your_role_on_the_system'] = $this->getPrivate('systemrole');
        $d['When_you_joined_the_site'] = ISODate($this->getPrivate('added'));
        $d['When_you_last_accessed_the_site'] = ISODate($this->getPrivate('lastaccess'));
        $d['When_we_last_checked_for_relevant_posts_for_you'] = ISODate($this->getPrivate('lastrelevantcheck'));
        $d['Whether_we_can_scan_your_messages_to_protect_other_users'] = $this->getPrivate('ripaconsent') ? 'Yes' : 'No';
        $d['Whether_we_can_publish_your_posts_outside_the_site'] = $this->getPrivate('publishconsent') ? 'Yes' : 'No';
        $d['Whether_your_email_is_bouncing'] = $this->getPrivate('bouncing') ? 'Yes' : 'No';
        $d['Permissions_you_have_on_the_site'] = $this->getPrivate('permissions');
        $d['Number_of_remaining_invitations_you_can_send_to_other_people'] = $this->getPrivate('invitesleft');

        $lastlocation = $this->user['lastlocation'];

        if ($lastlocation) {
            $l = new Location($this->dbhr, $this->dbhm, $lastlocation);
            $d['Last_location_you_posted_from'] = $l->getPrivate('name') . " (" . $l->getPrivate('lat') . ', ' . $l->getPrivate('lng') . ')';
        }

        $settings = $this->getPrivate('settings');

        if ($settings) {
            $settings = json_decode($settings, TRUE);

            $location = presdef('id', presdef('mylocation', $settings, []), NULL);

            if ($location) {
                $l = new Location($this->dbhr, $this->dbhm, $location);
                $d['Last_location_you_entered'] = $l->getPrivate('name') . ' (' . $l->getPrivate('lat') . ', ' . $l->getPrivate('lng') . ')';
            }

            $notifications = pres('notifications', $settings);

            $d['Notifications']['Send_email_notifications_for_chat_messages'] = presdef('email', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_email_notifications_of_chat_messages_you_send'] = presdef('emailmine', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_notifications_for_apps'] = presdef('app', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_push_notifications_to_web_browsers'] = presdef('push', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_Facebook_notifications'] = presdef('facebook', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_emails_about_notifications_on_the_site'] = presdef('notificationmails', $notifications, TRUE) ? 'Yes' : 'No';

            $d['Hide_profile_picture'] = presdef('useprofile', $settings, TRUE) ? 'Yes' : 'No';

            if ($this->isModerator()) {
                $d['Show_members_that_you_are_a_moderator'] = pres('showmod', $settings) ? 'Yes' : 'No';

                switch (presdef('modnotifs', $settings, 4)) {
                    case 24:
                        $d['Send_notifications_of_active_mod_work'] = 'After 24 hours';
                        break;
                    case 12:
                        $d['Send_notifications_of_active_mod_work'] = 'After 12 hours';
                        break;
                    case 4:
                        $d['Send_notifications_of_active_mod_work'] = 'After 4 hours';
                        break;
                    case 2:
                        $d['Send_notifications_of_active_mod_work'] = 'After 2 hours';
                        break;
                    case 1:
                        $d['Send_notifications_of_active_mod_work'] = 'After 1 hours';
                        break;
                    case 0:
                        $d['Send_notifications_of_active_mod_work'] = 'Immediately';
                        break;
                    case -1:
                        $d['Send_notifications_of_active_mod_work'] = 'Never';
                        break;
                }

                switch (presdef('backupmodnotifs', $settings, 12)) {
                    case 24:
                        $d['Send_notifications_of_backup_mod_work'] = 'After 24 hours';
                        break;
                    case 12:
                        $d['Send_notifications_of_backup_mod_work'] = 'After 12 hours';
                        break;
                    case 4:
                        $d['Send_notifications_of_backup_mod_work'] = 'After 4 hours';
                        break;
                    case 2:
                        $d['Send_notifications_of_backup_mod_work'] = 'After 2 hours';
                        break;
                    case 1:
                        $d['Send_notifications_of_backup_mod_work'] = 'After 1 hours';
                        break;
                    case 0:
                        $d['Send_notifications_of_backup_mod_work'] = 'Immediately';
                        break;
                    case -1:
                        $d['Send_notifications_of_backup_mod_work'] = 'Never';
                        break;
                }

                $d['Show_members_that_you_are_a_moderator'] = presdef('showmod', $settings, TRUE) ? 'Yes' : 'No';
            }
        }

        # Invitations.  Only show what we sent; the outcome is not this user's business.
        error_log("...invitations");
        $invites = $this->listInvitations();
        $d['invitations'] = [];

        foreach ($invites as $invite) {
            $d['invitations'][] = [
                'email' => $invite['email'],
                'date' => ISODate($invite['date'])
            ];
        }

        error_log("...emails");
        $d['emails'] = $this->getEmails();

        foreach ($d['emails'] as &$email) {
            $email['added'] = ISODate($email['added']);

            if ($email['validated']) {
                $email['validated'] = ISODate($email['validated']);
            }
        }

        $phones = $this->dbhr->preQuery("SELECT number FROM users_phones WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($phones as $phone) {
            $d['phone'] = $phone['number'];
        }

        error_log("...logins");
        $d['logins'] = $this->dbhr->preQuery("SELECT type, uid, added, lastaccess FROM users_logins WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['logins'] as &$dd) {
            $dd['added'] = ISOdate($dd['added']);
            $dd['lastaccess'] = ISOdate($dd['lastaccess']);
        }

        error_log("...memberships");
        $d['memberships'] = $this->getMemberships();

        error_log("...memberships history");
        $sql = "SELECT DISTINCT memberships_history.*, groups.nameshort, groups.namefull FROM memberships_history INNER JOIN groups ON memberships_history.groupid = groups.id WHERE userid = ? ORDER BY added ASC;";
        $membs = $this->dbhr->preQuery($sql, [$this->id]);
        foreach ($membs as &$memb) {
            $name = $memb['namefull'] ? $memb['namefull'] : $memb['nameshort'];
            $memb['namedisplay'] = $name;
            $memb['added'] = ISODate($memb['added']);
        }

        $d['membershipshistory'] = $membs;

        error_log("...searches");
        $d['searches'] = $this->dbhr->preQuery("SELECT search_history.date, search_history.term, locations.name AS location FROM search_history LEFT JOIN locations ON search_history.locationid = locations.id WHERE search_history.userid = ? ORDER BY search_history.date ASC;", [
            $this->id
        ]);

        foreach ($d['searches'] as &$s) {
            $s['date'] = ISODate($s['date']);
        }

        error_log("...alerts");
        $d['alerts'] = $this->dbhr->preQuery("SELECT subject, responded, response FROM alerts_tracking INNER JOIN alerts ON alerts_tracking.alertid = alerts.id WHERE userid = ? AND responded IS NOT NULL ORDER BY responded ASC;", [
            $this->id
        ]);

        foreach ($d['alerts'] as &$s) {
            $s['responded'] = ISODate($s['responded']);
        }

        error_log("...donations");
        $d['donations'] = $this->dbhr->preQuery("SELECT * FROM users_donations WHERE userid = ? ORDER BY timestamp ASC;", [
            $this->id
        ]);

        foreach ($d['donations'] as &$s) {
            $s['timestamp'] = ISODate($s['timestamp']);
        }

        error_log("...bans");
        $d['bans'] = [];

        $bans = $this->dbhr->preQuery("SELECT * FROM users_banned WHERE byuser = ?;", [
            $this->id
        ]);

        foreach ($bans as $ban) {
            $g = Group::get($this->dbhr, $this->dbhm, $ban['groupid']);
            $u = User::get($this->dbhr, $this->dbhm, $ban['userid']);
            $d['bans'][] = [
                'date' => ISODate($ban['date']),
                'group' => $g->getName(),
                'email' => $u->getEmailPreferred(),
                'userid' => $ban['userid']
            ];
        }

        error_log("...spammers");
        $d['spammers'] = $this->dbhr->preQuery("SELECT * FROM spam_users WHERE byuserid = ? ORDER BY added ASC;", [
            $this->id
        ]);

        foreach ($d['spammers'] as &$s) {
            $s['added'] = ISODate($s['added']);
            $u = User::get($this->dbhr, $this->dbhm, $s['userid']);
            $s['email'] = $u->getEmailPreferred();
        }

        $d['spamdomains'] = $this->dbhr->preQuery("SELECT domain, date FROM spam_whitelist_links WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['spamdomains'] as &$s) {
            $s['date'] = ISODate($s['date']);
        }

        error_log("...images");
        $images = $this->dbhr->preQuery("SELECT id FROM users_images WHERE userid = ?;", [
            $this->id
        ]);

        $d['images'] = [];

        foreach ($images as $image) {
            $a = new Attachment($this->dbhr, $this->dbhm, $image['id'], Attachment::TYPE_USER);
            $d['images'][] = [
                'thumb' => $a->getPath(TRUE)
            ];
        }

        error_log("...notifications");
        $d['notifications'] = $this->dbhr->preQuery("SELECT timestamp, url FROM users_notifications WHERE touser = ? AND seen = 1;", [
            $this->id
        ]);

        foreach ($d['notifications'] as &$n) {
            $n['timestamp'] = ISODate($n['timestamp']);
        }

        error_log("...addresses");
        $d['addresses'] = [];

        $addrs = $this->dbhr->preQuery("SELECT * FROM users_addresses WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($addrs as $addr) {
            $a = new Address($this->dbhr, $this->dbhm, $addr['id']);
            $d['addresses'][] = $a->getPublic();
        }

        error_log("...events");
        $d['communityevents'] = [];

        $events = $this->dbhr->preQuery("SELECT id FROM communityevents WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($events as $event) {
            $e = new CommunityEvent($this->dbhr, $this->dbhm, $event['id']);
            $d['communityevents'][] = $e->getPublic();
        }

        error_log("...volunteering");
        $d['volunteering'] = [];

        $events = $this->dbhr->preQuery("SELECT id FROM volunteering WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($events as $event) {
            $e = new Volunteering($this->dbhr, $this->dbhm, $event['id']);
            $d['volunteering'][] = $e->getPublic();
        }

        error_log("...comments");
        $d['comments'] = [];
        $comms = $this->dbhr->preQuery("SELECT * FROM users_comments WHERE byuserid = ? ORDER BY date ASC;", [
            $this->id
        ]);

        foreach ($comms as &$comm) {
            $u = User::get($this->dbhr, $this->dbhm, $comm['userid']);
            $comm['email'] = $u->getEmailPreferred();
            $comm['date'] = ISODate($comm['date']);
            $d['comments'][] = $comm;
        }

        error_log("...ratings");
        $d['ratings'] = $this->getRated();

        error_log("...locations");
        $d['locations'] = [];

        $locs = $this->dbhr->preQuery("SELECT * FROM locations_excluded WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($locs as $loc) {
            $g = Group::get($this->dbhr, $this->dbhm, $loc['groupid']);
            $l = new Location($this->dbhr, $this->dbhm, $loc['locationid']);
            $d['locations'][] = [
                'group' => $g->getName(),
                'location' => $l->getPrivate('name'),
                'date' => ISODate($loc['date'])
            ];
        }

        error_log("...messages");
        $msgs = $this->dbhr->preQuery("SELECT id FROM messages WHERE fromuser = ? ORDER BY arrival ASC;", [
            $this->id
        ]);

        $d['messages'] = [];

        foreach ($msgs as $msg) {
            $m = new Message($this->dbhr, $this->dbhm, $msg['id']);

            # Show all info here even moderator attributes.  This wouldn't normally be shown to users, but none
            # of it is confidential really.
            $thisone = $m->getPublic(FALSE, FALSE, TRUE);

            if (count($thisone['groups']) > 0) {
                $g = Group::get($this->dbhr, $this->dbhm, $thisone['groups'][0]['groupid']);
                $thisone['groups'][0]['namedisplay'] = $g->getName();
            }

            $d['messages'][] = $thisone;
        }

        # Chats.  Can't use listForUser as that filters on various things and has a ModTools vs FD distinction, and
        # we're interested in information we have provided.  So we get the chats mentioned in the roster (we have
        # provided information about being online) and where we have sent or reviewed a chat message.
        error_log("...chats");
        $chatids = $this->dbhr->preQuery("SELECT DISTINCT  id FROM chat_rooms INNER JOIN (SELECT DISTINCT chatid FROM chat_roster WHERE userid = ? UNION SELECT DISTINCT chatid FROM chat_messages WHERE userid = ? OR reviewedby = ?) t ON t.chatid = chat_rooms.id ORDER BY latestmessage ASC;", [
            $this->id,
            $this->id,
            $this->id
        ]);

        $d['chatrooms'] = [];
        $count = 0;

        foreach ($chatids as $chatid) {
            # We don't return the chat name because it's too slow to produce.
            $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid['id']);
            $thisone = [
                'id' => $chatid['id'],
                'name' => $r->getPublic($this)['name'],
                'messages' => []
            ];

            $sql = "SELECT date, lastip FROM chat_roster WHERE `chatid` = ? AND userid = ?;";
            $roster = $this->dbhr->preQuery($sql, [$chatid['id'], $this->id]);
            foreach ($roster as $rost) {
                $thisone['lastip'] = $rost['lastip'];
                $thisone['date'] = ISODate($rost['date']);
            }

            # Get the messages we have sent in this chat.
            $msgs = $this->dbhr->preQuery("SELECT id FROM chat_messages WHERE chatid = ? AND (userid = ? OR reviewedby = ?);", [
                $chatid['id'],
                $this->id,
                $this->id
            ]);

            foreach ($msgs as $msg) {
                $cm = new ChatMessage($this->dbhr, $this->dbhm, $msg['id']);
                $thismsg = $cm->getPublic();

                # Strip out most of the refmsg detail - it's not ours and we need to save volume of data.
                $refmsg = pres('refmsg', $thismsg);

                if ($refmsg) {
                    $thismsg['refmsg'] = [
                        'id' => $msg['id'],
                        'subject' => presdef('subject', $refmsg, NULL)
                    ];
                }

                $thismsg['mine'] = presdef('userid', $thismsg, NULL) == $this->id;
                $thismsg['date'] = ISODate($thismsg['date']);
                $thisone['messages'][] = $thismsg;

                $count++;
//
//                if ($count > 200) {
//                    break 2;
//                }
            }

            if (count($thisone['messages']) > 0) {
                $d['chatrooms'][] = $thisone;
            }
        }

        error_log("...newsfeed");
        $newsfeeds = $this->dbhr->preQuery("SELECT * FROM newsfeed WHERE userid = ?;", [
            $this->id
        ]);

        $d['newsfeed'] = [];

        foreach ($newsfeeds as $newsfeed) {
            $n = new Newsfeed($this->dbhr, $this->dbhm, $newsfeed['id']);
            $thisone = $n->getPublic(FALSE, FALSE, FALSE, FALSE);
            $d['newsfeed'][] = $thisone;
        }

        $d['newsfeed_unfollows'] = $this->dbhr->preQuery("SELECT * FROM newsfeed_unfollow WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['newsfeed_unfollows'] as &$dd) {
            $dd['timestamp'] = ISODate($dd['timestamp']);
        }

        $d['newsfeed_likes'] = $this->dbhr->preQuery("SELECT * FROM newsfeed_likes WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['newsfeed_likes'] as &$dd) {
            $dd['timestamp'] = ISODate($dd['timestamp']);
        }

        $d['newsfeed_reports'] = $this->dbhr->preQuery("SELECT * FROM newsfeed_reports WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['newsfeed_reports'] as &$dd) {
            $dd['timestamp'] = ISODate($dd['timestamp']);
        }

        $d['aboutme'] = $this->dbhr->preQuery("SELECT timestamp, text FROM users_aboutme WHERE userid = ? AND LENGTH(text) > 5;", [
            $this->id
        ]);

        foreach ($d['aboutme'] as &$dd) {
            $dd['timestamp'] = ISODate($dd['timestamp']);
        }

        error_log("...stories");
        $d['stories'] = $this->dbhr->preQuery("SELECT date, headline, story FROM users_stories WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['stories'] as &$dd) {
            $dd['date'] = ISODate($dd['date']);
        }

        $d['stories_likes'] = $this->dbhr->preQuery("SELECT storyid FROM users_stories_likes WHERE userid = ?;", [
            $this->id
        ]);

        error_log("...exports");
        $d['exports'] = $this->dbhr->preQuery("SELECT userid, started, completed FROM users_exports WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['exports'] as &$dd) {
            $dd['started'] = ISODate($dd['started']);
            $dd['completed'] = ISODate($dd['completed']);
        }

        error_log("...logs");
        $l = new Log($this->dbhr, $this->dbhm);
        $ctx = NULL;
        $d['logs'] = $l->get(NULL, NULL, NULL, NULL, NULL, PHP_INT_MAX, $ctx, $this->id, TRUE);

        error_log("...add group to logs");
        $loggroups = [];
        foreach ($d['logs'] as &$log) {
            if (pres('groupid', $log)) {
                # Don't put the whole group info in there, as it is slow to get.
                if (!array_key_exists($log['groupid'], $loggroups)) {
                    $g = Group::get($this->dbhr, $this->dbhm, $log['groupid']);

                    if ($g->getId() == $log['groupid']) {
                        $loggroups[$log['groupid']] = [
                            'id' => $log['groupid'],
                            'nameshort' => $g->getPrivate('nameshort'),
                            'namedisplay' => $g->getName()
                        ];
                    } else {
                        $loggroups[$log['groupid']] = [
                            'id' => $log['groupid'],
                            'nameshort' => "DeletedGroup{$log['groupid']}",
                            'namedisplay' => "Deleted group #{$log['groupid']}"
                        ];
                    }
                }

                $log['group'] = $loggroups[$log['groupid']];
            }
        }

        $ret = $d;

        # There are some other tables with information which we don't return.  Here's what and why:
        # - Not part of the current UI so can't have any user data
        #     messages_likes, polls_users
        # - Covered by data that we do return from other tables
        #     messages_drafts, messages_history, messages_groups, messages_likes, messages_outcomes,
        #     messages_promises, users_modmails, modnotifs, users_chatlists_index, users_dashboard,
        #     users_nudges
        # - Transient logging data
        #     logs_emails, logs_sql, logs_api, logs_errors, logs_src
        # - Not provided by the user themselves
        #     user_comments, messages_reneged, spam_users, users_banned, users_stories_requested,
        #     users_thanks
        # - Inferred or derived data.  These are not considered to be provided by the user (see p10 of
        #   http://ec.europa.eu/newsroom/document.cfm?doc_id=44099)
        #     users_kudos, visualise

        # Compress the data in the DB because it can be huge.
        #
        error_log("...filter");
        filterResult($ret);
        error_log("...encode");
        $data = json_encode($ret);
        error_log("...encoded length " . strlen($data) . ", now compress");
        $data = gzdeflate($data);
        $this->dbhm->preExec("UPDATE users_exports SET completed = NOW(), data = ? WHERE id = ? AND tag = ?;", [
            $data,
            $exportid,
            $tag
        ]);
        error_log("...completed, length " . strlen($data));

        return ($ret);
    }

    function getExport($userid, $id, $tag)
    {
        $ret = NULL;

        $exports = $this->dbhr->preQuery("SELECT * FROM users_exports WHERE userid = ? AND id = ? AND tag = ?;", [
            $userid,
            $id,
            $tag
        ]);

        foreach ($exports as $export) {
            $ret = $export;
            $ret['requested'] = $ret['requested'] ? ISODate($ret['requested']) : NULL;
            $ret['started'] = $ret['started'] ? ISODate($ret['started']) : NULL;
            $ret['completed'] = $ret['completed'] ? ISODate($ret['completed']) : NULL;

            if ($ret['completed']) {
                # This has completed.  Return the data.  Will be zapped in cron exports..
                $ret['data'] = json_decode(gzinflate($export['data']), TRUE);
                $ret['infront'] = 0;
            } else {
                # Find how many are in front of us.
                $infront = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM users_exports WHERE id < ? AND completed IS NULL;", [
                    $id
                ]);

                $ret['infront'] = $infront[0]['count'];
            }
        }

        return ($ret);
    }

    public function forget()
    {
        # Wipe a user of personal data, for the GDPR right to be forgotten.  We don't delete the user entirely
        # otherwise it would message up the stats.

        # Clear name etc.
        $this->setPrivate('firstname', NULL);
        $this->setPrivate('lastname', NULL);
        $this->setPrivate('fullname', "Deleted User #" . $this->id);
        $this->setPrivate('settings', NULL);
        $this->setPrivate('yahooid', NULL);

        # Delete emails which aren't ours.
        $emails = $this->getEmails();

        foreach ($emails as $email) {
            if (!$email['ourdomain']) {
                $this->removeEmail($email['email']);
            }
        }

        # Delete all logins.
        $this->dbhm->preExec("DELETE FROM users_logins WHERE userid = ?;", [
            $this->id
        ]);

        # Delete any phone numbers.
        $this->dbhm->preExec("DELETE FROM users_phones WHERE userid = ?;", [
            $this->id
        ]);

        # Delete the content (but not subject) of any messages, and any email header information such as their
        # name and email address.
        $msgs = $this->dbhm->preQuery("SELECT id FROM messages WHERE fromuser = ?;", [
            $this->id
        ]);

        foreach ($msgs as $msg) {
            $this->dbhm->preExec("UPDATE messages SET fromip = NULL, message = NULL, envelopefrom = NULL, fromname = NULL, fromaddr = NULL, messageid = NULL, textbody = NULL, htmlbody = NULL WHERE id = ?;", [
                $msg['id']
            ]);

            # Delete outcome comments that they've added - just about might have personal data.
            $this->dbhm->preExec("UPDATE messages_outcomes SET comments = NULL WHERE msgid = ?;", [
                $msg['id']
            ]);
        }

        # Remove all the content of all chat messages which they have sent (but not received).
        $msgs = $this->dbhm->preQuery("SELECT id FROM chat_messages WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($msgs as $msg) {
            $this->dbhm->preExec("UPDATE chat_messages SET message = NULL WHERE id = ?;", [
                $msg['id']
            ]);
        }

        # Delete completely any community events, volunteering opportunities, newsfeed posts, searches and stories
        # they have created (their personal details might be in there), and any ratings by or about them.
        $this->dbhm->preExec("DELETE FROM communityevents WHERE userid = ?;", [
            $this->id
        ]);
        $this->dbhm->preExec("DELETE FROM volunteering WHERE userid = ?;", [
            $this->id
        ]);
        $this->dbhm->preExec("DELETE FROM newsfeed WHERE userid = ?;", [
            $this->id
        ]);
        $this->dbhm->preExec("DELETE FROM users_stories WHERE userid = ?;", [
            $this->id
        ]);
        $this->dbhm->preExec("DELETE FROM users_searches WHERE userid = ?;", [
            $this->id
        ]);
        $this->dbhm->preExec("DELETE FROM users_aboutme WHERE userid = ?;", [
            $this->id
        ]);
        $this->dbhm->preExec("DELETE FROM ratings WHERE rater = ?;", [
            $this->id
        ]);
        $this->dbhm->preExec("DELETE FROM ratings WHERE ratee = ?;", [
            $this->id
        ]);

        # Remove them from all groups.
        $membs = $this->getMemberships();

        foreach ($membs as $memb) {
            error_log(var_export($memb, TRUE));
            $this->removeMembership($memb['id']);
        }

        # Delete any postal addresses
        $this->dbhm->preExec("DELETE FROM users_addresses WHERE userid = ?;", [
            $this->id
        ]);

        # Delete any profile images
        $this->dbhm->preExec("DELETE FROM users_images WHERE userid = ?;", [
            $this->id
        ]);
    }

    public function userRetention($userid = NULL)
    {
        # Find users who:
        # - were added six months ago
        # - are not on any groups
        # - have not logged in for six months
        # - are not on the spammer list
        # - do not have mod notes
        # - have no logs for six months
        #
        # We have no good reason to keep any data about them, and should therefore purge them.
        $count = 0;
        $userq = $userid ? " users.id = $userid AND " : '';
        $mysqltime = date("Y-m-d", strtotime("6 months ago"));
        $sql = "SELECT users.id FROM users LEFT JOIN memberships ON users.id = memberships.userid LEFT JOIN spam_users ON users.id = spam_users.userid LEFT JOIN users_comments ON users.id = users_comments.userid WHERE $userq memberships.userid IS NULL AND spam_users.userid IS NULL AND spam_users.userid IS NULL AND users.lastaccess < '$mysqltime' AND systemrole = ?;";
        $users = $this->dbhr->preQuery($sql, [
            User::SYSTEMROLE_USER
        ], FALSE, FALSE);

        foreach ($users as $user) {
            $logs = $this->dbhr->preQuery("SELECT DATEDIFF(NOW(), timestamp) AS logsago FROM logs WHERE user = ? ORDER BY id DESC LIMIT 1;", [
                $user['id']
            ], FALSE, FALSE);

            #error_log("#{$user['id']} Found logs " . count($logs) . " age " . (count($logs) > 0 ? $logs['0']['logsago'] : ' none '));

            if (count($logs) == 0 || $logs[0]['logsago'] > 90) {
                error_log("...forget user #{$user['id']} " . (count($logs) > 0 ? $logs[0]['logsago'] : ''));
                $u = new User($this->dbhr, $this->dbhm, $user['id']);
                $u->forget();
                $count++;
            }
        }

        error_log("...removed $count");

        return ($count);
    }

    public function recordActive()
    {
        # We record this on an hourly basis.  Avoid pointless mod ops for cluster health.
        $now = date("Y-m-d H:00:00", time());
        $already = $this->dbhr->preQuery("SELECT * FROM users_active WHERE userid = ? AND timestamp = ?;", [
            $this->id,
            $now
        ], FALSE, FALSE);

        if (count($already) == 0) {
            $this->dbhm->background("INSERT IGNORE INTO users_active (userid, timestamp) VALUES ({$this->id}, '$now');");
        }
    }

    public function getActive()
    {
        $active = $this->dbhr->preQuery("SELECT * FROM users_active WHERE userid = ?;", [$this->id], FALSE, FALSE);
        return ($active);
    }

    public function mostActive($gid, $limit = 20)
    {
        $earliest = date("Y-m-d", strtotime("Midnight 30 days ago"));

        $users = $this->dbhr->preQuery("SELECT users_active.userid, COUNT(*) AS count FROM users_active inner join users ON users.id = users_active.userid INNER JOIN memberships ON memberships.userid = users.id WHERE groupid = ? AND systemrole = ? AND timestamp >= ? GROUP BY users_active.userid ORDER BY count DESC LIMIT $limit", [
            $gid,
            User::SYSTEMROLE_USER,
            $earliest
        ]);

        $ret = [];

        foreach ($users as $user) {
            $u = User::get($this->dbhr, $this->dbhm, $user['userid']);
            $thisone = $u->getPublic();
            $thisone['groupid'] = $gid;
            $thisone['email'] = $u->getEmailPreferred();

            if (pres('memberof', $thisone)) {
                foreach ($thisone['memberof'] as $group) {
                    if ($group['id'] == $gid) {
                        $thisone['joined'] = $group['added'];
                    }
                }
            }

            $ret[] = $thisone;
        }

        return ($ret);
    }

    public function formatPhone($num)
    {
        $num = str_replace(' ', '', $num);
        $num = str_replace('+44', '', $num);
        $num = str_replace('+', '', $num);

        if (substr($num, 0, 1) === '0') {
            $num = substr($num, 1);
        }

        $num = "+44$num";

        return ($num);
    }

    public function sms($msg, $url, $from = TWILIO_FROM, $sid = TWILIO_SID, $auth = TWILIO_AUTH)
    {
        $phones = $this->dbhr->preQuery("SELECT * FROM users_phones WHERE userid = ? AND valid = 1;", [
            $this->id
        ]);

        foreach ($phones as $phone) {
            try {
                $last = presdef('lastsent', $phone, NULL);
                $last = $last ? strtotime($last) : NULL;

                # Only send one SMS per day.  This keeps the cost down.
                if (!$last || (time() - $last > 24 * 60 * 60)) {
                    $client = new Client($sid, $auth);

                    $text = "$msg Click $url Don't reply to this text.  No more texts sent today.";
                    $rsp = $client->messages->create(
                        $this->formatPhone($phone['number']),
                        array(
                            'from' => $from,
                            'body' => $text,
                            'statusCallback' => 'https://' . USER_SITE . '/twilio/status.php'
                        )
                    );

                    $this->dbhr->preExec("UPDATE users_phones SET lastsent = NOW(), count = count + 1, lastresponse = ? WHERE id = ?;", [
                        $rsp->sid,
                        $phone['id']
                    ]);
                    error_log("Sent SMS to {$phone['number']} result {$rsp->sid}");
                } else {
                    error_log("Don't send, too recent");
                }
            } catch (Exception $e) {
                error_log("Send to {$phone['number']} failed with " . $e->getMessage());
                $this->dbhr->preExec("UPDATE users_phones SET lastsent = NOW(), lastresponse = ? WHERE id = ?;", [
                    $e->getMessage(),
                    $phone['id']
                ]);
            }

        }
    }

    public function addPhone($phone)
    {
        $this->dbhm->preExec("REPLACE INTO users_phones (userid, number, valid) VALUES (?, ?, 1);", [
            $this->id,
            $this->formatPhone($phone),
        ]);

        return($this->dbhm->lastInsertId());
    }

    public function removePhone()
    {
        $this->dbhm->preExec("DELETE FROM users_phones WHERE userid = ?;", [
            $this->id
        ]);
    }

    public function getPhone()
    {
        $ret = NULL;
        $phones = $this->dbhr->preQuery("SELECT * FROM users_phones WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($phones as $phone) {
            $ret = $phone['number'];
        }

        return ($ret);
    }

    public function setAboutMe($text) {
        $this->dbhm->preExec("INSERT INTO users_aboutme (userid, text) VALUES (?, ?);", [
            $this->id,
            $text
        ]);

        return($this->dbhm->lastInsertId());
    }

    public function rate($rater, $ratee, $rating) {
        $ret = NULL;

        if ($rater != $ratee) {
            # Can't rate yourself.
            $this->dbhm->preExec("REPLACE INTO ratings (rater, ratee, rating) VALUES (?, ?, ?);", [
                $rater,
                $ratee,
                $rating
            ]);

            $ret = $this->dbhm->lastInsertId();
        }

        return($ret);
    }

    public function getRating() {
        $ratings = $this->dbhr->preQuery("SELECT COUNT(*) AS count, rating FROM ratings WHERE ratee = ? GROUP BY rating;", [
            $this->id
        ], FALSE, FALSE);

        $ret = [
            User::RATING_UP => 0,
            User::RATING_DOWN => 0,
            User::RATING_MINE => NULL
        ];

        foreach ($ratings as $rate) {
            $ret[$rate['rating']] = $rate['count'];
        }

        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        if ($myid) {
            $ratings = $this->dbhr->preQuery("SELECT rating FROM ratings WHERE ratee = ? AND rater = ?;", [
                $this->id,
                $myid
            ], FALSE, FALSE);

            foreach ($ratings as $rating) {
                $ret[User::RATING_MINE] = $rating['rating'];
            }
        }

        return($ret);
    }

    public function getRated() {
        $rateds = $this->dbhr->preQuery("SELECT * FROM ratings WHERE rater = ?;", [
            $this->id
        ]);

        foreach ($rateds as &$rate) {
            $rate['timestamp'] = ISODate($rate['timestamp']);
        }

        return($rateds);
    }
}
