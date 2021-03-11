<?php
namespace Freegle\Iznik;

require_once(IZNIK_BASE . '/mailtemplates/verifymail.php');
require_once(IZNIK_BASE . '/mailtemplates/welcome/forgotpassword.php');
require_once(IZNIK_BASE . '/mailtemplates/invite.php');
require_once(IZNIK_BASE . '/lib/wordle/functions.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');

use Jenssegers\ImageHash\ImageHash;
use Twilio\Rest\Client;

class User extends Entity
{
    # We have a cache of users, because we create users a _lot_, and this can speed things up significantly by avoiding
    # hitting the DB.
    static $cache = [];
    static $cacheDeleted = [];
    const CACHE_SIZE = 100;

    const OPEN_AGE = 90;

    const KUDOS_NEWBIE = 'Newbie';
    const KUDOS_OCCASIONAL = 'Occasional';
    const KUDOS_FREQUENT = 'Frequent';
    const KUDOS_AVID = 'Avid';

    const RATING_UP = 'Up';
    const RATING_DOWN = 'Down';
    const RATING_MINE = 'Mine';
    const RATING_UNKNOWN = 'Unknown';

    const TRUST_EXCLUDED = 'Excluded';
    const TRUST_DECLINED = 'Declined';
    const TRUST_BASIC = 'Basic';
    const TRUST_MODERATE = 'Moderate';
    const TRUST_ADVANCED = 'Advanced';


    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'firstname', 'lastname', 'fullname', 'systemrole', 'settings', 'yahooid', 'newslettersallowed', 'relevantallowed', 'publishconsent', 'ripaconsent', 'bouncing', 'added', 'invitesleft', 'onholidaytill', 'covidconfirmed');

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
    const PERM_GIFTAID = 'GiftAid';
    const PERM_SPAM_ADMIN = 'SpamAdmin';

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
    const SRC_NOTICEBOARD = 'noticeboard';

    # Chat mod status
    const CHAT_MODSTATUS_MODERATED = 'Moderated';
    const CHAT_MODSTATUS_UNMODERATED = 'Unmoderated';
    const CHAT_MODSTATUS_FULLY = 'Fully';

    # Newsfeed mod status
    const NEWSFEED_UNMODERATED = 'Unmoderated';
    const NEWSFEED_MODERATED = 'Moderated';
    const NEWSFEED_SUPPRESSED = 'Suppressed';

    # 2 decimal places is roughly 1km.
    const BLUR_NONE = NULL;
    const BLUR_100M = 3;
    const BLUR_1K = 2;

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
        #error_log("Construct user " .  debug_backtrace()[1]['function'] . "," . debug_backtrace()[2]['function']);
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
        $this->spammer = [];

        if ($id) {
            # Fetch the user.  There are so many users that there is no point trying to use the query cache.
            $sql = "SELECT * FROM users WHERE id = ?;";

            $users = $dbhr->preQuery($sql, [
                $id
            ]);

            foreach ($users as $user) {
                $this->user = $user;
                $this->id = $id;
            }
        }
    }

    public static function get(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $usecache = TRUE, $testonly = FALSE)
    {
        $u = NULL;

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
                } else if (!$testonly) {
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
        if (!$testonly) {
            #error_log("$id not in cache");
            $u = new User($dbhr, $dbhm, $id);

            if ($id && count(User::$cache) < User::CACHE_SIZE) {
                # Store for next time
                #error_log("store $id in cache");
                User::$cache[$id] = $u;
                User::$cacheDeleted[$id] = FALSE;
            }
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

    public function hashPassword($pw, $salt = PASSWORD_SALT)
    {
        return sha1($pw . $salt);
    }

    public function login($suppliedpw, $force = FALSE)
    {
        # TODO lockout
        if ($this->id) {
            $logins = $this->getLogins(TRUE);
            foreach ($logins as $login) {
                $pw = $this->hashPassword($suppliedpw, Utils::presdef('salt', $login, PASSWORD_SALT));

                if ($force || ($login['type'] == User::LOGIN_NATIVE && $login['uid'] == $this->id && strtolower($pw) == strtolower($login['credentials']))) {
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
        $ret = TRUE;

        if (Utils::presdef('id', $_SESSION, NULL) != $this->id) {
            # We're not already logged in as this user.
            $ret = FALSE;

            $sql = "SELECT * FROM users_logins WHERE userid = ? AND type = ? AND credentials = ?;";
            $logins = $this->dbhr->preQuery($sql, [$this->id, User::LOGIN_LINK, $key]);
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

    public function getName($default = TRUE, $atts = NULL)
    {
        $atts = $atts ? $atts : $this->user;

        $name = NULL;

        # We may or may not have the knowledge about how the name is split out, depending
        # on the sign-in mechanism.
        if (Utils::pres('fullname', $atts)) {
            $name = $atts['fullname'];
        } else if (Utils::pres('firstname', $atts) || Utils::pres('lastname', $atts)) {
            $first = Utils::pres('firstname', $atts);
            $last = Utils::pres('lastname', $atts);

            $name = $first && $last ? "$first $last" : ($first ? $first : $last);
        }

        # Make sure we don't return an email if somehow one has snuck in.
        $name = ($name && strpos($name, '@') !== FALSE) ? substr($name, 0, strpos($name, '@')) : $name;

        # If we are logged in as this user and it's showing deleted then we've resurrected it; give it a new name.
        $resurrect = isset($_SESSION) && Utils::presdef('id', $_SESSION, NULL) == $this->id && strpos($name, 'Deleted User') === 0;

        if ($default &&
            $this->id &&
            (strlen(trim($name)) === 0 ||
                $name == 'A freegler' ||
                $resurrect ||
                (strlen($name) == 32 && preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $name)) ||
                strpos($name, 'FBUser') !== FALSE)
        ) {
            # We have:
            # - no name, or
            # - a name derived from a Yahoo ID which is a hex string, which looks silly
            # - A freegler, which was an old way of anonymising.
            # - A very old FBUser name
            $u = new User($this->dbhr, $this->dbhm, $atts['id']);
            $email = $u->inventEmail();
            $name = substr($email, 0, strpos($email, '-'));
            $u->setPrivate('fullname', $name);
            $u->setPrivate('inventedname', 1);
        }

        # Stop silly long names.
        $name = strlen($name) > 32 ? (substr($name, 0, 32) . '...') : $name;

        return ($name);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function create($firstname, $lastname, $fullname, $reason = '', $yahooid = NULL)
    {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        try {
            $src = Utils::presdef('src', $_SESSION, NULL);
            $rc = $this->dbhm->preExec("INSERT INTO users (firstname, lastname, fullname, yahooid, source) VALUES (?, ?, ?, ?, ?)",
                [$firstname, $lastname, $fullname, $yahooid, $src]);
            $id = $this->dbhm->lastInsertId();
        } catch (\Exception $e) {
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
        $spamwords = [];
        $ws = $this->dbhr->preQuery("SELECT word FROM spam_keywords");
        foreach ($ws as $w) {
            $spamwords[] = strtolower($w['word']);
        }

        $lengths = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/distinct_word_lengths.json'), true);
        $bigrams = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/word_start_bigrams.json'), true);
        $trigrams = json_decode(file_get_contents(IZNIK_BASE . '/lib/wordle/data/trigrams.json'), true);

        $pw = '';

        do {
            $length = \Wordle\array_weighted_rand($lengths);
            $start = \Wordle\array_weighted_rand($bigrams);
            $word = \Wordle\fill_word($start, $length, $trigrams);

            if (!in_array(strtolower($word), $spamwords)) {
                $pw .= $word;
            }
        } while (strlen($pw) < 6);

        $pw = strtolower($pw);
        return ($pw);
    }

    public function getEmails($recent = FALSE, $nobouncing = FALSE)
    {
        #error_log("Get emails " .  debug_backtrace()[1]['function']);
        # Don't return canon - don't need it on the client.
        $ordq = $recent ? 'id' : 'preferred';

        if (!$this->emails || $ordq != $this->emailsord) {
            $bounceq = $nobouncing ? " AND bounced IS NULL " : '';
            $sql = "SELECT id, userid, email, preferred, added, validated FROM users_emails WHERE userid = ? $bounceq ORDER BY $ordq DESC, email ASC;";
            #error_log("$sql, {$this->id}");
            $this->emails = $this->dbhr->preQuery($sql, [$this->id]);
            $this->emailsord = $ordq;

            foreach ($this->emails as &$email) {
                $email['ourdomain'] = Mail::ourDomain($email['email']);
            }
        }

        return ($this->emails);
    }

    public function getEmailsById($uids) {
        $ret = [];

        if ($uids && count($uids)) {
            $sql = "SELECT id, userid, email, preferred, added, validated FROM users_emails WHERE userid IN (" . implode(',', $uids) . ") ORDER BY preferred DESC, email ASC;";
            $emails = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);

            foreach ($emails as $email) {
                $email['ourdomain'] = Mail::ourDomain($email['email']);
                $ret[$email['userid']][] = $email;
            }
        }

        return ($ret);
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
            if (!Mail::ourDomain($email['email']) && strpos($email['email'], '@yahoogroups.') === FALSE && strpos($email['email'], GROUP_DOMAIN) === FALSE) {
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
            if (Mail::ourDomain($email['email'])) {
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
        $email = trim($email);

        # Invalidate cache.
        $this->emails = NULL;

        if (stripos($email, '-owner@yahoogroups.co') !== FALSE ||
            stripos($email, '-volunteers@' . GROUP_DOMAIN) !== FALSE ||
            stripos($email, '-auto@' . GROUP_DOMAIN) !== FALSE) {
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
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
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
        #
        # We shouldn't come through here with prohibited, but if we do, best send to pending.
        $ps = $this->getMembershipAtt($groupid, 'ourPostingStatus');
        $coll = (!$ps || $ps == Group::POSTING_MODERATED || $ps == Group::POSTING_PROHIBITED) ? MessageCollection::PENDING : MessageCollection::APPROVED;
        return ($coll);
    }

    public function isBanned($groupid) {
        $sql = "SELECT * FROM users_banned WHERE userid = ? AND groupid = ?;";
        $banneds = $this->dbhr->preQuery($sql, [
            $this->id,
            $groupid
        ]);

        foreach ($banneds as $banned) {
            return TRUE;
        }

        return FALSE;
    }

    public function addMembership($groupid, $role = User::ROLE_MEMBER, $emailid = NULL, $collection = MembershipCollection::APPROVED, $message = NULL, $byemail = NULL, $addedhere = TRUE)
    {
        $this->memberships = NULL;
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);

        Session::clearSessionCache();

        # Check if we're banned
        if ($this->isBanned($groupid)) {
            return FALSE;
        }

        # We don't want to use REPLACE INTO because the membershipid is a foreign key in some tables, and if the
        # membership already exists, then this would cause us to delete and re-add it, which would result in the
        # row in the child table being deleted.
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
            list ($transport, $mailer) = Mail::getMailer();
            $message = \Swift_Message::newInstance()
                ->setSubject("Welcome to " . $g->getPrivate('nameshort'))
                ->setFrom($g->getAutoEmail())
                ->setReplyTo($g->getModsEmail())
                ->setTo($byemail)
                ->setDate(time())
                ->setBody("Pleased to meet you.");

            Mail::addHeaders($message, Mail::WELCOME);
            $this->sendIt($mailer, $message);
        }
        // @codeCoverageIgnoreEnd

        if ($added) {
            # The membership didn't already exist.  We might want to send a welcome mail.
            $atts = $g->getPublic();

            if (($addedhere) && ($atts['welcomemail'] || $message) && $collection == MembershipCollection::APPROVED && $g->getPrivate('onhere')) {
                # They are now approved.  We need to send a per-group welcome mail.
                $this->sendWelcome($message ? $message : $atts['welcomemail'], $groupid, $g, $atts);
            }

            $l = new Log($this->dbhr, $this->dbhm);
            $l->log([
                'type' => Log::TYPE_GROUP,
                'subtype' => Log::SUBTYPE_JOINED,
                'user' => $this->id,
                'byuser' => $me ? $me->getId() : NULL,
                'groupid' => $groupid
            ]);
        }

        # Check whether this user now counts as a possible spammer.
        $s = new Spam($this->dbhr, $this->dbhm);
        $s->checkUser($this->id);

        return ($rc);
    }

    public function sendWelcome($welcome, $gid, $g = NULL, $atts = NULL, $review = FALSE) {
        $g = $g ? $g : Group::get($this->dbhr, $this->dbhm, $gid);
        $atts = $atts ? $atts : $g->getPublic();

        $to = $this->getEmailPreferred();

        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/welcome');
        $twig = new \Twig_Environment($loader);

        $html = $twig->render('group.html', [
            'email' => $to,
            'message' => nl2br($welcome),
            'review' => $review,
            'groupname' => $g->getName()
        ]);

        if ($to) {
            list ($transport, $mailer) = Mail::getMailer();
            $message = \Swift_Message::newInstance()
                ->setSubject(($review ? "Please review: " : "") . "Welcome to " . $atts['namedisplay'])
                ->setFrom([$g->getAutoEmail() => $atts['namedisplay'] . ' Volunteers'])
                ->setReplyTo([$g->getModsEmail() => $atts['namedisplay'] . ' Volunteers'])
                ->setTo($to)
                ->setDate(time())
                ->setBody($welcome);

            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
            # Outlook.
            $htmlPart = \Swift_MimePart::newInstance();
            $htmlPart->setCharset('utf-8');
            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
            $htmlPart->setContentType('text/html');
            $htmlPart->setBody($html);
            $message->attach($htmlPart);

            Mail::addHeaders($message, Mail::WELCOME, $this->getId());

            $this->sendIt($mailer, $message);
        }
    }

    public function cacheMemberships($id = NULL)
    {
        $id = $id ? $id : $this->id;

        # We get all the memberships in a single call, because some members are on many groups and this can
        # save hundreds of calls to the DB.
        if (!$this->memberships) {
            $this->memberships = [];

            $membs = $this->dbhr->preQuery("SELECT memberships.*, groups.type FROM memberships INNER JOIN groups ON groups.id = memberships.groupid WHERE userid = ?;", [ $id ]);
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
        if (Utils::pres($groupid, $this->memberships)) {
            $val = Utils::presdef($att, $this->memberships[$groupid], NULL);
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

    public function removeMembership($groupid, $ban = FALSE, $spam = FALSE, $byemail = NULL)
    {
        $this->clearMembershipCache();
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $meid = $me ? $me->getId() : NULL;

        // @codeCoverageIgnoreStart
        //
        // Let them know.  We always want to let TN know if a member is removed/banned so that they can't see
        // the messages.
        if ($byemail || $this->isTN()) {
            list ($transport, $mailer) = Mail::getMailer();
            $message = \Swift_Message::newInstance()
                ->setSubject("Farewell from " . $g->getPrivate('nameshort'))
                ->setFrom($g->getAutoEmail())
                ->setReplyTo($g->getModsEmail())
                ->setTo($this->getEmailPreferred())
                ->setDate(time())
                ->setBody("Parting is such sweet sorrow.");

            Mail::addHeaders($message, Mail::REMOVED);

            $this->sendIt($mailer, $message);
        }
        // @codeCoverageIgnoreEnd

        if ($ban) {
            $sql = "INSERT IGNORE INTO users_banned (userid, groupid, byuser) VALUES (?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                $groupid,
                $meid
            ]);
        }

        # Now remove the membership.
        $rc = $this->dbhm->preExec("DELETE FROM memberships WHERE userid = ? AND groupid = ?;",
            [
                $this->id,
                $groupid
            ]);

        if ($this->dbhm->rowsAffected()) {
            $l = new Log($this->dbhr, $this->dbhm);
            $l->log([
                'type' => Log::TYPE_GROUP,
                'subtype' => Log::SUBTYPE_LEFT,
                'user' => $this->id,
                'byuser' => $meid,
                'groupid' => $groupid,
                'text' => $spam ? "Autoremoved spammer" : ($ban ? "via ban" : NULL)
            ]);
        }

        return ($rc);
    }

    public function getMembershipGroupIds($modonly = FALSE, $grouptype = NULL, $id = NULL) {
        $id = $id ? $id : $this->id;

        $ret = [];
        $modq = $modonly ? " AND role IN ('Owner', 'Moderator') " : "";
        $typeq = $grouptype ? (" AND `type` = " . $this->dbhr->quote($grouptype)) : '';
        $publishq = Session::modtools() ? "" : "AND groups.publish = 1";
        $sql = "SELECT groupid FROM memberships INNER JOIN groups ON groups.id = memberships.groupid $publishq WHERE userid = ? $modq $typeq;";
        $groups = $this->dbhr->preQuery($sql, [$id]);
        #error_log("getMemberships $sql {$id} " . var_export($groups, TRUE));
        $groupids = array_filter(array_column($groups, 'groupid'));
        return $groupids;
    }

    public function getMemberships($modonly = FALSE, $grouptype = NULL, $getwork = FALSE, $pernickety = FALSE, $id = NULL)
    {
        $id = $id ? $id : $this->id;

        $ret = [];
        $modq = $modonly ? " AND role IN ('Owner', 'Moderator') " : "";
        $typeq = $grouptype ? (" AND `type` = " . $this->dbhr->quote($grouptype)) : '';
        $publishq = Session::modtools() ? "" : "AND groups.publish = 1";
        $sql = "SELECT type, memberships.settings, collection, emailfrequency, eventsallowed, volunteeringallowed, groupid, role, configid, ourPostingStatus, CASE WHEN namefull IS NOT NULL THEN namefull ELSE nameshort END AS namedisplay FROM memberships INNER JOIN groups ON groups.id = memberships.groupid $publishq WHERE userid = ? $modq $typeq ORDER BY LOWER(namedisplay) ASC;";
        $groups = $this->dbhr->preQuery($sql, [$id]);
        #error_log("getMemberships $sql {$id} " . var_export($groups, TRUE));

        $c = new ModConfig($this->dbhr, $this->dbhm);

        # Get all the groups efficiently.
        $groupids = array_filter(array_column($groups, 'groupid'));
        $gc = new GroupCollection($this->dbhr, $this->dbhm, $groupids);
        $groupobjs = $gc->get();
        $getworkids = [];
        $groupsettings = [];

        for ($i = 0; $i < count($groupids); $i++) {
            $group = $groups[$i];
            $g = $groupobjs[$i];
            $one = $g->getPublic();

            $one['role'] = $group['role'];
            $one['collection'] = $group['collection'];
            $amod = ($one['role'] == User::ROLE_MODERATOR || $one['role'] == User::ROLE_OWNER);
            $one['configid'] = Utils::presdef('configid', $group, NULL);

            if ($amod && !Utils::pres('configid', $one)) {
                # Get a config using defaults.
                $one['configid'] = $c->getForGroup($id, $group['groupid']);
            }

            $one['mysettings'] = $this->getGroupSettings($group['groupid'], Utils::presdef('configid', $one, NULL), $id);

            # If we don't have our own email on this group we won't be sending mails.  This is what affects what
            # gets shown on the Settings page for the user, and we only want to check this here
            # for performance reasons.
            $one['mysettings']['emailfrequency'] = ($group['type'] === Group::GROUP_FREEGLE &&
                ($pernickety || $this->sendOurMails($g, FALSE, FALSE))) ?
                (array_key_exists('emailfrequency', $one['mysettings']) ? $one['mysettings']['emailfrequency'] :  24)
                : 0;

            $groupsettings[$group['groupid']] = $one['mysettings'];

            if ($getwork) {
                # We need to find out how much work there is whether or not we are an active mod because we need
                # to be able to see that it is there.  The UI shows it less obviously.
                if ($amod) {
                    $getworkids[] = $group['groupid'];
                }
            }

            $ret[] = $one;
        }

        if ($getwork) {
            # Get all the work.  This is across all groups for performance.
            $g = new Group($this->dbhr, $this->dbhm);
            $work = $g->getWorkCounts($groupsettings, $groupids);

            foreach ($getworkids as $groupid) {
                foreach ($ret as &$group) {
                    if ($group['id'] == $groupid) {
                        $group['work'] = $work[$groupid];
                    }
                }
            }
        }

        return ($ret);
    }

    public function getConfigs($all)
    {
        $ret = [];
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($all) {
            # We can see configs which
            # - we created
            # - are used by mods on groups on which we are a mod
            # - defaults
            $modships = $me ? $this->getModeratorships() : [];
            $modships = count($modships) > 0 ? $modships : [0];

            $sql = "SELECT DISTINCT id FROM ((SELECT configid AS id FROM memberships WHERE groupid IN (" . implode(',', $modships) . ") AND role IN ('Owner', 'Moderator') AND configid IS NOT NULL) UNION (SELECT id FROM mod_configs WHERE createdby = {$this->id} OR `default` = 1)) t;";
            $ids = $this->dbhr->preQuery($sql);
        } else {
            # We only want to see the configs that we are actively using.  This reduces the size of what we return
            # for people on many groups.
            $sql = "SELECT DISTINCT configid AS id FROM memberships WHERE userid = ? AND configid IS NOT NULL;";
            $ids = $this->dbhr->preQuery($sql, [ $me->getId() ]);
        }

        $configids = array_filter(array_column($ids, 'id'));

        if ($configids) {
            # Get all the info we need for the modconfig object in a single SELECT for performance.  This is particularly
            # valuable for people on many groups and therefore with access to many modconfigs.
            $sql = "SELECT DISTINCT mod_configs.*, 
        CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS createdname 
        FROM mod_configs LEFT JOIN users ON users.id = mod_configs.createdby
        WHERE mod_configs.id IN (" . implode(',', $configids) . ");";
            $configs = $this->dbhr->preQuery($sql);

            # Also get all the bulk ops and standard messages, again for performance.
            $stdmsgs = $this->dbhr->preQuery("SELECT DISTINCT * FROM mod_stdmsgs WHERE configid IN (" . implode(',', $configids) . ");");
            $bulkops = $this->dbhr->preQuery("SELECT * FROM mod_bulkops WHERE configid IN (" . implode(',', $configids) . ");");

            foreach ($configs as $config) {
                $c = new ModConfig($this->dbhr, $this->dbhm, $config['id'], $config, $stdmsgs, $bulkops);
                $thisone = $c->getPublic(FALSE);

                if (Utils::pres('createdby', $config)) {
                    $ctx = NULL;
                    $thisone['createdby'] = [
                        'id' => $config['createdby'],
                        'displayname' => $config['createdname']
                    ];
                }

                $ret[] = $thisone;
            }
        }

        # Return in alphabetical order.
        usort($ret, function ($a, $b) {
            return (strcmp(strtolower($a['name']), strtolower($b['name'])));
        });

        return ($ret);
    }

    public function getModeratorships($id = NULL)
    {
        $this->cacheMemberships($id);

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
        if (session_status() !== PHP_SESSION_NONE && array_key_exists('modorowner', $_SESSION) && array_key_exists($this->id, $_SESSION['modorowner']) && array_key_exists($groupid, $_SESSION['modorowner'][$this->id])) {
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

    public function getLogins($credentials = TRUE, $id = NULL, $excludelink = FALSE)
    {
        $excludelinkq = $excludelink ? (" AND type != '" . User::LOGIN_LINK . "'") : '';

        $logins = $this->dbhr->preQuery("SELECT * FROM users_logins WHERE userid = ? $excludelinkq ORDER BY lastaccess DESC;",
            [$id ? $id : $this->id]);

        foreach ($logins as &$login) {
            if (!$credentials) {
                unset($login['credentials']);
            }
            $login['added'] = Utils::ISODate($login['added']);
            $login['lastaccess'] = Utils::ISODate($login['lastaccess']);
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

    public function addLogin($type, $uid, $creds = NULL, $salt = PASSWORD_SALT)
    {
        if ($type == User::LOGIN_NATIVE) {
            # Native login - encrypt the password a bit.  The password salt is global in FD, but per-login for users
            # migrated from Norfolk.
            $creds = $this->hashPassword($creds, $salt);
            $uid = $this->id;
        }

        # If the login with this type already exists in the table, that's fine.
        $sql = "INSERT INTO users_logins (userid, uid, type, credentials, salt) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE credentials = ?, salt = ?;";
        $rc = $this->dbhm->preExec($sql,
            [$this->id, $uid, $type, $creds, $salt, $creds, $salt]);

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

    public function moderatorForUser($userid, $allowmod = FALSE)
    {
        # There are times when we want to check whether we can administer a user, but when we are not immediately
        # within the context of a known group.  We can administer a user when:
        # - they're only a user themselves
        # - we are a mod on one of the groups on which they are a member.
        # - it's us
        if ($userid != $this->getId()) {
            $u = User::get($this->dbhr, $this->dbhm, $userid);

            $usermemberships = [];
            $modq = $allowmod ? ", 'Moderator', 'Owner'" : '';
            $groups = $this->dbhr->preQuery("SELECT groupid FROM memberships WHERE userid = ? AND role IN ('Member' $modq);", [$userid]);
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

    public function getGroupSettings($groupid, $configid = NULL, $id = NULL)
    {
        $id = $id ? $id : $this->id;

        # We have some parameters which may give us some info which saves queries
        $this->cacheMemberships($id);

        # Defaults match memberships ones in Group.php.
        $defaults = [
            'active' => 1,
            'showchat' => 1,
            'pushnotify' => 1,
            'eventsallowed' => 1,
            'volunteeringallowed' => 1
        ];

        $settings = $defaults;

        if (Utils::pres($groupid, $this->memberships)) {
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
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

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

    public function getActiveCounts() {
        $users = [
            $this->id => [
                'id' => $this->id
            ]];

        $this->getActiveCountss($users);
        return($users[$this->id]['activecounts']);
    }

    public function getActiveCountss(&$users) {
        $start = date('Y-m-d', strtotime(User::OPEN_AGE . " days ago"));
        $uids = array_filter(array_column($users, 'id'));

        if (count($uids)) {
            $counts = $this->dbhr->preQuery("SELECT messages.fromuser AS userid, COUNT(*) AS count, messages.type, messages_outcomes.outcome FROM messages LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser IN (" . implode(',', $uids) . ") AND messages.arrival > ? AND collection = ? AND messages_groups.deleted = 0 AND messages_outcomes.id IS NULL GROUP BY messages.fromuser, messages.type, messages_outcomes.outcome;", [
                $start,
                MessageCollection::APPROVED
            ]);

            foreach ($users as $user) {
                $offers = 0;
                $wanteds = 0;

                foreach ($counts as $count) {
                    if ($count['userid'] == $user['id']) {
                        if ($count['type'] == Message::TYPE_OFFER) {
                            $offers += $count['count'];
                        } else if ($count['type'] == Message::TYPE_WANTED) {
                            $wanteds += $count['count'];
                        }
                    }
                }

                $users[$user['id']]['activecounts'] = [
                    'offers' => $offers,
                    'wanteds' => $wanteds
                ];
            }
        }
    }

    public function getInfos(&$users) {
        $uids = array_filter(array_column($users, 'id'));

        $start = date('Y-m-d', strtotime(User::OPEN_AGE . " days ago"));
        $days90 = date("Y-m-d", strtotime("90 days ago"));
        $userq = "userid IN (" . implode(',', $uids) . ")";

        foreach ($uids as $uid) {
            $users[$uid]['info']['replies'] = 0;
            $users[$uid]['info']['taken'] = 0;
            $users[$uid]['info']['reneged'] = 0;
            $users[$uid]['info']['collected'] = 0;
        }

        // We can combine some queries into a single one.  This is better for performance because it saves on
        // the round trip (seriously, I've measured it, and it's worth doing).
        //
        // No need to check on the chat room type as we can only get messages of type Interested in a User2User chat.
        $counts = $this->dbhr->preQuery("SELECT t0.id AS theuserid, t1.*, t3.*, t4.*, t5.* FROM
(SELECT id FROM users WHERE id in (" . implode(',', $uids) . ")) t0 LEFT JOIN                                                                
(SELECT COUNT(DISTINCT refmsgid) AS replycount, userid FROM chat_messages WHERE $userq AND date > ? AND refmsgid IS NOT NULL AND type = ?) t1 ON t1.userid = t0.id LEFT JOIN 
(SELECT COUNT(DISTINCT(msgid)) AS reneged, userid FROM messages_reneged WHERE $userq AND timestamp > ?) t3 ON t3.userid = t0.id LEFT JOIN
(SELECT COUNT(DISTINCT msgid) AS collected, messages_by.userid FROM messages_by INNER JOIN messages ON messages.id = messages_by.msgid INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id AND messages.type = ? AND chat_messages.type = ? WHERE chat_messages.$userq AND messages_by.$userq AND messages_by.userid != messages.fromuser AND messages.arrival >= '$days90') t4 ON t4.userid = t0.id LEFT JOIN
(SELECT timestamp AS abouttime, text AS abouttext, userid FROM users_aboutme WHERE $userq ORDER BY timestamp DESC LIMIT 1) t5 ON t5.userid = t0.id
;", [
            $start,
            ChatMessage::TYPE_INTERESTED,
            $start,
            Message::TYPE_OFFER,
            ChatMessage::TYPE_INTERESTED
        ]);

        foreach ($users as $uid => $user) {
            foreach ($counts as $count) {
                if ($count['theuserid'] == $users[$uid]['id']) {
                    $users[$uid]['info']['replies'] = $count['replycount'] ? $count['replycount'] : 0;
                    $users[$uid]['info']['reneged'] = $count['reneged'] ? $count['reneged'] : 0;
                    $users[$uid]['info']['collected'] = $count['collected'] ? $count['collected'] : 0;

                    if (Utils::pres('abouttime', $count)) {
                        $users[$uid]['info']['aboutme'] = [
                            'timestamp' => Utils::ISODate($count['abouttime']),
                            'text' => $count['abouttext']
                        ];
                    }
                }
            }
        }

        $sql = "SELECT messages.fromuser AS userid, COUNT(*) AS count, messages.type, messages_outcomes.outcome FROM messages LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser IN (" . implode(',', $uids) . ") AND messages.arrival > ? AND collection = ? AND messages_groups.deleted = 0 GROUP BY messages.fromuser, messages.type, messages_outcomes.outcome;";
        $counts = $this->dbhr->preQuery($sql, [
            $start,
            MessageCollection::APPROVED
        ]);

        foreach ($users as $uid => $user) {
            $users[$uid]['info']['offers'] = 0;
            $users[$uid]['info']['wanteds'] = 0;
            $users[$uid]['info']['openoffers'] = 0;
            $users[$uid]['info']['openwanteds'] = 0;

            foreach ($counts as $count) {
                if ($count['userid'] == $users[$uid]['id']) {
                    if ($count['type'] == Message::TYPE_OFFER) {
                        $users[$uid]['info']['offers'] += $count['count'];

                        if (!Utils::pres('outcome', $count)) {
                            $users[$uid]['info']['openoffers'] += $count['count'];
                        }
                    } else if ($count['type'] == Message::TYPE_WANTED) {
                        $users[$uid]['info']['wanteds'] += $count['count'];

                        if (!Utils::pres('outcome', $count)) {
                            $users[$uid]['info']['openwanteds'] += $count['count'];
                        }
                    }
                }
            }
        }

        # Distance away.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($me) {
            list ($mylat, $mylng, $myloc) = $me->getLatLng();

            if ($myloc !== NULL) {
                $latlngs = $this->getLatLngs($users);

                foreach ($latlngs as $userid => $latlng) {
                    $users[$userid]['info']['milesaway'] = $this->getDistanceBetween($mylat, $mylng, $latlng['lat'], $latlng['lng']);
                }
            }

            $this->getPublicLocations($users);
        }

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $replytimes = $r->replyTimes($uids);

        foreach ($replytimes as $uid => $replytime) {
            $users[$uid]['info']['replytime'] = $replytime;
        }

        $nudges = $r->nudgeCounts($uids);

        foreach ($nudges as $uid => $nudgecount) {
            $users[$uid]['info']['nudges'] = $nudgecount;
        }

        $ratings = $this->getRatings($uids);

        foreach ($ratings as $uid => $rating) {
            $users[$uid]['info']['ratings'] = $rating;
        }

        $replies = $this->getExpectedReplies($uids);

        foreach ($replies as $reply) {
            if ($reply['expectee']) {
                $users[$reply['expectee']]['info']['expectedreply'] = $reply['count'];
            }
        }
    }
    
    public function getInfo()
    {
        # Extra user info.
        $ret = [];
        $ret['openage'] = User::OPEN_AGE;
        $start = date('Y-m-d', strtotime("{$ret['openage']} days ago"));
        $days90 = date("Y-m-d", strtotime("90 days ago"));

        // We can combine some queries into a single one.  This is better for performance because it saves on
        // the round trip (seriously, I've measured it, and it's worth doing).
        //
        // No need to check on the chat room type as we can only get messages of type Interested in a User2User chat.
        $replies = $this->dbhr->preQuery("SELECT 
(SELECT COUNT(DISTINCT refmsgid) FROM chat_messages WHERE userid = ? AND date > ? AND refmsgid IS NOT NULL AND type = ?) AS replycount, 
(SELECT COUNT(DISTINCT(msgid)) AS count FROM messages_reneged WHERE userid = ? AND timestamp > ?) AS reneged,
(SELECT COUNT(DISTINCT(msgid)) AS count FROM messages_by 
    INNER JOIN messages ON messages.id = messages_by.msgid 
    INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id AND messages.type = ? AND chat_messages.type = ? 
    WHERE chat_messages.userid = ? AND messages_by.userid = ? AND messages_by.userid != messages.fromuser AND messages.arrival >= '$days90') AS collected,
(SELECT CONCAT(timestamp, ',', text) FROM users_aboutme WHERE userid = ? ORDER BY timestamp DESC LIMIT 1) AS abouttext
;", [
            $this->id,
            $start,
            ChatMessage::TYPE_INTERESTED,
            $this->id,
            $start,
            ChatMessage::TYPE_INTERESTED,
            Message::TYPE_OFFER,
            $this->id,
            $this->id,
            $this->id
        ]);

        $ret['replies'] = $replies[0]['replycount'];
        $ret['reneged'] = $replies[0]['reneged'];
        $ret['collected'] = $replies[0]['collected'];

        if (Utils::pres('abouttext', $replies[0])) {
            $p = strpos($replies[0]['abouttext'], ',');
            $ret['aboutme'] = [
                'timestamp' => Utils::ISODate(substr($replies[0]['abouttext'], 0, $p)),
                'text' => substr($replies[0]['abouttext'], $p + 1)
            ];
        }

        $counts = $this->dbhr->preQuery("SELECT COUNT(*) AS count, messages.type, messages_outcomes.outcome FROM messages LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser = ? AND messages.arrival > ? AND collection = ? AND messages_groups.deleted = 0 GROUP BY messages.type, messages_outcomes.outcome;", [
            $this->id,
            $start,
            MessageCollection::APPROVED
        ]);

        $ret['offers'] = 0;
        $ret['wanteds'] = 0;
        $ret['openoffers'] = 0;
        $ret['openwanteds'] = 0;

        foreach ($counts as $count) {
            if ($count['type'] == Message::TYPE_OFFER) {
                $ret['offers'] += $count['count'];

                if (!Utils::pres('outcome', $count)) {
                    $ret['openoffers'] += $count['count'];
                }
            } else if ($count['type'] == Message::TYPE_WANTED) {
                $ret['wanteds'] += $count['count'];

                if (!Utils::pres('outcome', $count)) {
                    $ret['openwanteds'] += $count['count'];
                }
            }
        }

        # Distance away.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($me) {
            list ($mylat, $mylng, $myloc) = $me->getLatLng();
            $ret['milesaway'] = $this->getDistance($mylat, $mylng);
            $ret['publiclocation'] = $this->getPublicLocation();
        }

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $ret['replytime'] = $r->replyTime($this->id);
        $ret['nudges'] = $r->nudgeCount($this->id);

        $ret['ratings'] = $this->getRating();

        $replies = $this->getExpectedReplies([ $this->id ]);

        foreach ($replies as $reply) {
            $ret['expectedreply'] = $reply['count'];
        }

        return ($ret);
    }

    public function getAboutMe() {
        $ret = NULL;

        $aboutmes = $this->dbhr->preQuery("SELECT * FROM users_aboutme WHERE userid = ? ORDER BY timestamp DESC LIMIT 1;", [
            $this->id
        ]);

        foreach ($aboutmes as $aboutme) {
            $ret = [
                'timestamp' => Utils::ISODate($aboutme['timestamp']),
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

    public function getDistance($mylat, $mylng) {
        list ($tlat, $tlng, $tloc) = $this->getLatLng();
        #error_log("Get distance $mylat, $mylng, $tlat, $tlng = " . $this->getDistanceBetween($mylat, $mylng, $tlat, $tlng));
        return($this->getDistanceBetween($mylat, $mylng, $tlat, $tlng));
    }

    public function getDistanceBetween($mylat, $mylng, $tlat, $tlng)
    {
        $p1 = new POI($mylat, $mylng);

        # We need to make sure that we don't reveal the actual location (well, the postcode location) to
        # someone attempting to triangulate.  So first we move the location a bit based on something which
        # can't be known about a user - a hash of their ID and the password salt.
        $tlat += ($this->md5_hex_to_dec(md5(PASSWORD_SALT . $this->id)) - 0.5) / 100;
        $tlng += ($this->md5_hex_to_dec(md5($this->id . PASSWORD_SALT)) - 0.5) / 100;

        # Now randomise the distance a bit each time we get it, so that anyone attempting repeated measurements
        # will get conflicting results around the precise location that isn't actually theirs.  But still close
        # enough to be useful for our purposes.
        $tlat += mt_rand(-100, 100) / 20000;
        $tlng += mt_rand(-100, 100) / 20000;

        $p2 = new POI($tlat, $tlng);
        $metres = $p1->getDistanceInMetersTo($p2);
        $miles = $metres / 1609.344;
        $miles = $miles > 2 ? round($miles) : round($miles, 1);
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
        $users = [
            $this->id => [
                'id' => $this->id
            ]
        ];

        $this->getLatLngs($users);
        $this->getPublicLocations($users);

        return($users[$this->id]['info']['publiclocation']);
    }

    public function ensureAvatar(&$atts)
    {
        # This involves querying external sites, so we need to use it with care, otherwise we can hang our
        # system.  It can also cause updates, so if we call it lots of times, it can result in cluster issues.
        $forcedefault = FALSE;
        $settings = Utils::presdef('settings', $atts, NULL);

        if ($settings) {
            if (array_key_exists('useprofile', $settings) && !$settings['useprofile']) {
                $forcedefault = TRUE;
            }
        }

        if (!$forcedefault && $atts['profile']['default']) {
            # See if we can do better than a default.
            $emails = $this->getEmails($atts['id']);

            try {
                foreach ($emails as $email) {
                    if (stripos($email['email'], 'gmail') || stripos($email['email'], 'googlemail')) {
                        # We can try to find profiles for gmail users.
                        $json = @file_get_contents("http://picasaweb.google.com/data/entry/api/user/{$email['email']}?alt=json");
                        $j = json_decode($json, TRUE);

                        if ($j && Utils::pres('entry', $j) && Utils::pres('gphoto$thumbnail', $j['entry']) && Utils::pres('$t', $j['entry']['gphoto$thumbnail'])) {
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
                        $url = "https://trashnothing.com/api/users/{$matches[1]}/profile-image?default=" . urlencode('https://' . IMAGE_DOMAIN . '/defaultprofile.png');
                        $atts['profile'] = [
                            'url' => $url,
                            'turl' => $url,
                            'default' => FALSE,
                            'TN' => TRUE
                        ];
                    } else if (!Mail::ourDomain($email['email'])) {
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
                            if (Utils::presdef('useprofile', $atts['settings'], TRUE)) {
                                // As of October 2020 we can no longer just access the profile picture via the UID, we need to make a
                                // call to the Graph API to fetch it.
                                $f = new Facebook($this->dbhr, $this->dbhm);
                                $atts['profile'] = $f->getProfilePicture($login['uid']);
                            }
                        }
                    }
                }
            } catch (Throwable $e) {}

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
                $atts['id'],
                $atts['profile']['default'] ? NULL : $atts['profile']['url'],
                $atts['profile']['default'],
                $hash
            ]);
        }
    }

    public function filterDefault(&$profile, &$hash) {
        $hasher = new ImageHash;
        $data = $profile['url'] && strlen($profile['url']) ? @file_get_contents($profile['url']) : NULL;
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
            $profile['url'] = 'https://' . IMAGE_DOMAIN . '/defaultprofile.png';
            $profile['turl'] = 'https://' . IMAGE_DOMAIN . '/defaultprofile.png';
            $profile['default'] = TRUE;
            $hash = NULL;
        }
    }

    public function getPublic($groupids = NULL, $history = TRUE, $comments = TRUE, $memberof = TRUE, $applied = TRUE, $modmailsonly = FALSE, $emailhistory = FALSE, $msgcoll = [ MessageCollection::APPROVED ], $historyfull = FALSE)
    {
        $atts = [];

        if ($this->id) {
            $users = [ $this->user ];
            $rets = $this->getPublics($users, $groupids, $history, $comments, $memberof, $applied, $modmailsonly, $emailhistory, $msgcoll, $historyfull);
            $atts = $rets[$this->id];
        }

        return($atts);
    }

    public function getPublicAtts(&$rets, $users, $me) {
        foreach ($users as &$user) {
            if (!array_key_exists($user['id'], $rets)) {
                $rets[$user['id']] = [];
            }

            $atts = $this->publicatts;

            if (Session::modtools()) {
                # We have some extra attributes.
                $atts[] = 'deleted';
                $atts[] = 'lastaccess';
            }

            foreach ($atts as $att) {
                $rets[$user['id']][$att] = Utils::presdef($att, $user, NULL);
            }

            $rets[$user['id']]['settings'] = Utils::presdef('settings', $user, NULL) ? json_decode($user['settings'], TRUE) : ['dummy' => TRUE];

            if (Utils::pres('mylocation', $rets[$user['id']]['settings']) && Utils::pres('groupsnear', $rets[$user['id']]['settings']['mylocation'])) {
                # This is large - no need for it.
                $rets[$user['id']]['settings']['mylocation']['groupsnear'] = NULL;
            }

            $rets[$user['id']]['settings']['notificationmails'] = array_key_exists('notificationmails', $rets[$user['id']]['settings']) ? $rets[$user['id']]['settings']['notificationmails'] : TRUE;
            $rets[$user['id']]['settings']['engagement'] = array_key_exists('engagement', $rets[$user['id']]['settings']) ? $rets[$user['id']]['settings']['engagement'] : TRUE;
            $rets[$user['id']]['settings']['modnotifs'] = array_key_exists('modnotifs', $rets[$user['id']]['settings']) ? $rets[$user['id']]['settings']['modnotifs'] : 4;
            $rets[$user['id']]['settings']['backupmodnotifs'] = array_key_exists('backupmodnotifs', $rets[$user['id']]['settings']) ? $rets[$user['id']]['settings']['backupmodnotifs'] : 12;

            $rets[$user['id']]['displayname'] = $this->getName(TRUE, $user);

            $rets[$user['id']]['added'] = Utils::ISODate($user['added']);

            foreach (['fullname', 'firstname', 'lastname'] as $att) {
                # Make sure we don't return an email if somehow one has snuck in.
                $rets[$user['id']][$att] = strpos($rets[$user['id']][$att], '@') !== FALSE ? substr($rets[$user['id']][$att], 0, strpos($rets[$user['id']][$att], '@')) : $rets[$user['id']][$att];
            }

            if ($me && $rets[$user['id']]['id'] == $me->getId()) {
                # Add in private attributes for our own entry.
                $rets[$user['id']]['emails'] = $me->getEmails();
                $rets[$user['id']]['email'] = $me->getEmailPreferred();
                $rets[$user['id']]['relevantallowed'] = $me->getPrivate('relevantallowed');
                $rets[$user['id']]['permissions'] = $me->getPrivate('permissions');
            }

            if ($me && ($me->isModerator() || $user['id'] == $me->getId())) {
                # Mods can see email settings, no matter which group.
                $rets[$user['id']]['onholidaytill'] = (Utils::pres('onholidaytill', $rets[$user['id']]) && (time() < strtotime($rets[$user['id']]['onholidaytill']))) ? Utils::ISODate($rets[$user['id']]['onholidaytill']) : NULL;
            } else {
                # Don't show some attributes unless they're a mod or ourselves.
                $ismod = $rets[$user['id']]['systemrole'] == User::SYSTEMROLE_ADMIN ||
                    $rets[$user['id']]['systemrole'] == User::SYSTEMROLE_SUPPORT ||
                    $rets[$user['id']]['systemrole'] == User::SYSTEMROLE_MODERATOR;
                $showmod = $ismod && Utils::presdef('showmod', $rets[$user['id']]['settings'], FALSE);
                $rets[$user['id']]['settings'] = ['showmod' => $showmod];
                $rets[$user['id']]['yahooid'] = NULL;
            }

            if (Utils::pres('deleted', $rets[$user['id']])) {
                $rets[$user['id']]['deleted'] = Utils::ISODate($rets[$user['id']]['deleted']);
            }

            if (Utils::pres('lastaccess', $rets[$user['id']])) {
                $rets[$user['id']]['lastaccess'] = Utils::ISODate($rets[$user['id']]['lastaccess']);
            }
        }
    }
    
    public function getPublicProfiles(&$rets) {
        $userids = array_filter(array_keys($rets));

        if ($userids && count($userids)) {
            foreach ($rets as &$ret) {
                $ret['profile'] = [
                    'url' => 'https://' . IMAGE_DOMAIN . '/defaultprofile.png',
                    'turl' => 'https://' . IMAGE_DOMAIN . '/defaultprofile.png',
                    'default' => TRUE
                ];
            }

            # Ordering by id ASC means we'll end up with the most recent value in our output.
            $sql = "SELECT * FROM users_images WHERE userid IN (" . implode(',', $userids) . ") ORDER BY userid, id ASC;";

            $profiles = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);

            foreach ($profiles as $profile) {
                # Get a profile.  This function is called so frequently that we can't afford to query external sites
                # within it, so if we don't find one, we default to none.
                if (Utils::pres('settings', $rets[$profile['userid']]) &&
                    gettype($rets[$profile['userid']]['settings']) == 'array' &&
                    (!array_key_exists('useprofile', $rets[$profile['userid']]['settings']) || $rets[$profile['userid']]['settings']['useprofile'])) {
                    # We found a profile that we can use.
                    if (!$profile['default']) {
                        # If it's a gravatar image we can return a thumbnail url that specifies a different size.
                        $turl = Utils::pres('url', $profile) ? $profile['url'] : ('https://' . IMAGE_DOMAIN . "/tuimg_{$profile['id']}.jpg");
                        $turl = strpos($turl, 'https://www.gravatar.com') === 0 ? str_replace('?s=200', '?s=100', $turl) : $turl;
                        $rets[$profile['userid']]['profile'] = [
                            'id' => $profile['id'],
                            'url' => Utils::pres('url', $profile) ? $profile['url'] : ('https://' . IMAGE_DOMAIN . "/uimg_{$profile['id']}.jpg"),
                            'turl' => $turl,
                            'default' => FALSE
                        ];
                    }
                }
            }
        }
    }

    public function getPublicHistory($me, &$rets, $users, $groupids, $historyfull, $systemrole, $msgcoll = [ MessageCollection::APPROVED ]) {
        $userids = array_filter(array_keys($rets));

        foreach ($rets as &$atts) {
            $atts['messagehistory'] = [];
        }

        # Add in the message history - from any of the emails associated with this user.
        #
        # We want one entry in here for each repost, so we LEFT JOIN with the reposts table.
        $sql = NULL;

        if (count($userids)) {
            $collq = " AND messages_groups.collection IN ('" . implode("','", $msgcoll) . "') ";
            $earliest = $historyfull ? '1970-01-01' : date('Y-m-d', strtotime("midnight 30 days ago"));
            $delq = $historyfull ? '' : ' AND messages_groups.deleted = 0';

            if ($groupids && count($groupids) > 0) {
                # On these groups.  Have to be a bit careful about getting the posting date as GREATEST can return NULL
                # if one of the arguments is NULL.
                $groupq = implode(',', $groupids);
                $sql = "SELECT GREATEST(COALESCE(messages_postings.date, messages.arrival), COALESCE(messages_postings.date, messages.arrival)) AS postdate, messages_outcomes.outcome, messages.fromuser, messages.id, messages.fromaddr, messages.arrival, messages.date, messages_groups.collection, messages_groups.deleted, messages_postings.date AS repostdate, messages_postings.repost, messages_postings.autorepost, messages.subject, messages.type, DATEDIFF(NOW(), messages.date) AS daysago, messages_groups.groupid FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid AND groupid IN ($groupq) $collq AND fromuser IN (" . implode(',', $userids) . ") $delq LEFT JOIN messages_postings ON messages.id = messages_postings.msgid LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id HAVING postdate > ? ORDER BY postdate DESC;";
            } else if ($systemrole == User::SYSTEMROLE_SUPPORT || $systemrole == User::SYSTEMROLE_ADMIN) {
                # We can see all groups.
                $sql = "SELECT GREATEST(COALESCE(messages_postings.date, messages.arrival), COALESCE(messages_postings.date, messages.arrival)) AS postdate, messages_outcomes.outcome, messages.fromuser, messages.id, messages.fromaddr, messages.arrival, messages.date, messages_groups.collection, messages_groups.deleted, messages_postings.date AS repostdate, messages_postings.repost, messages_postings.autorepost, messages.subject, messages.type, DATEDIFF(NOW(), messages.date) AS daysago, messages_groups.groupid FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid $collq AND fromuser IN (" . implode(',', $userids) . ") $delq LEFT JOIN messages_postings ON messages.id = messages_postings.msgid LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id HAVING postdate > ? ORDER BY postdate DESC;";
            }
        }

        if ($sql) {
             $histories = $this->dbhr->preQuery($sql, [
                $earliest
             ]);

             foreach ($rets as $userid => $ret) {
                 foreach ($histories as $history) {
                     if ($history['fromuser'] == $ret['id']) {
                         $history['arrival'] = Utils::pres('repostdate', $history) ? Utils::ISODate($history['repostdate']) : Utils::ISODate($history['arrival']);
                         $history['date'] = Utils::ISODate($history['date']);
                         $rets[$userid]['messagehistory'][] = $history;
                     }
                 }
             }
        }

        # Add in a count of recent "modmail" type logs which a mod might care about.
        $modships = $me ? $me->getModeratorships() : [];
        $modships = count($modships) == 0 ? [0] : $modships;
        $sql = "SELECT COUNT(*) AS count, userid FROM `users_modmails` WHERE userid IN (" . implode(',', $userids) . ") AND groupid IN (" . implode(',', $modships) . ") GROUP BY userid;";
        $modmails = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);

        foreach ($userids as $userid) {
            $rets[$userid]['modmails'] = 0;
        }

        foreach ($rets as $userid => $ret) {
            foreach ($modmails as $modmail) {
                if ($modmail['userid'] == $ret['id']) {
                    $rets[$userid]['modmails'] = $modmail['count'] ? $modmail['count'] : 0;
                }
            }
        }
    }

    public function getPublicMemberOf(&$rets, $me, $freeglemod, $memberof, $systemrole) {
        $userids = [];

        foreach ($rets as $ret) {
            $ret['activearea'] = NULL;

            if (!Utils::pres('memberof', $ret)) {
                # We haven't provided the complete list already, e.g. because the user is suspect.
                $userids[] = $ret['id'];
            }
        }

        if ($memberof &&
            count($userids) &&
            ($systemrole == User::ROLE_MODERATOR || $systemrole == User::SYSTEMROLE_ADMIN || $systemrole == User::SYSTEMROLE_SUPPORT)
        ) {
            # Gt the recent ones (which preserves some privacy for the user but allows us to spot abuse) and any which
            # are on our groups.
            $addmax = ($systemrole == User::SYSTEMROLE_ADMIN || $systemrole == User::SYSTEMROLE_SUPPORT) ? PHP_INT_MAX : 31;
            $modids = array_merge([0], $me->getModeratorships());
            $freegleq = $freeglemod ? " OR groups.type = 'Freegle' " : '';
            $sql = "SELECT DISTINCT memberships.*, memberships.collection AS coll, groups.onhere, groups.nameshort, groups.namefull, groups.lat, groups.lng, groups.type FROM memberships INNER JOIN groups ON memberships.groupid = groups.id WHERE userid IN (" . implode(',', $userids) . ") AND (DATEDIFF(NOW(), memberships.added) <= $addmax OR memberships.groupid IN (" . implode(',', $modids) . ") $freegleq);";
            $groups = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
            #error_log("Get groups $sql, {$this->id}");

            foreach ($rets as &$ret) {
                $ret['memberof'] = [];
                $ourEmailId = NULL;

                if (Utils::pres('emails', $ret)) {
                    foreach ($ret['emails'] as $email) {
                        if (Mail::ourDomain($email['email'])) {
                            $ourEmailId = $email['id'];
                        }
                    }
                }

                foreach ($groups as $group) {
                    if ($ret['id'] === $group['userid']) {
                        $name = $group['namefull'] ? $group['namefull'] : $group['nameshort'];

                        $ret['memberof'][] = [
                            'id' => $group['groupid'],
                            'membershipid' => $group['id'],
                            'namedisplay' => $name,
                            'nameshort' => $group['nameshort'],
                            'added' => Utils::ISODate(Utils::pres('yadded', $group) ? $group['yadded'] : $group['added']),
                            'collection' => $group['coll'],
                            'role' => $group['role'],
                            'emailfrequency' => $group['emailfrequency'],
                            'eventsallowed' => $group['eventsallowed'],
                            'volunteeringallowed' => $group['volunteeringallowed'],
                            'ourpostingstatus' => $group['ourPostingStatus'],
                            'type' => $group['type'],
                            'onhere' => $group['onhere'],
                            'reviewrequestedat' => $group['reviewrequestedat'] ? Utils::ISODate($group['reviewrequestedat']) : NULL,
                            'reviewreason' => $group['reviewreason'],
                            'reviewedat' => $group['reviewedat'] ? Utils::ISODate($group['reviewedat']) : NULL,
                        ];

                        if ($group['lat'] && $group['lng']) {
                            $box = Utils::presdef('activearea', $ret, NULL);

                            $ret['activearea'] = [
                                'swlat' => $box == NULL ? $group['lat'] : min($group['lat'], $box['swlat']),
                                'swlng' => $box == NULL ? $group['lng'] : min($group['lng'], $box['swlng']),
                                'nelng' => $box == NULL ? $group['lng'] : max($group['lng'], $box['nelng']),
                                'nelat' => $box == NULL ? $group['lat'] : max($group['lat'], $box['nelat'])
                            ];
                        }
                    }
                }
            }
        }
    }

    public function getPublicApplied(&$rets, $me, $freeglemod, $applied, $systemrole) {
        $userids = array_keys($rets);

        if ($applied &&
            $systemrole == User::ROLE_MODERATOR ||
            $systemrole == User::SYSTEMROLE_ADMIN ||
            $systemrole == User::SYSTEMROLE_SUPPORT
        ) {
            # As well as being a member of a group, they might have joined and left, or applied and been rejected.
            # This is useful info for moderators.
            $sql = "SELECT DISTINCT memberships_history.*, groups.nameshort, groups.namefull, groups.lat, groups.lng FROM memberships_history INNER JOIN groups ON memberships_history.groupid = groups.id WHERE userid IN (" . implode(',', $userids) . ") AND DATEDIFF(NOW(), added) <= 31 ORDER BY added DESC;";
            $membs = $this->dbhr->preQuery($sql);

            foreach ($rets as &$ret) {
                $ret['applied'] = [];
                $ret['activedistance'] = NULL;

                foreach ($membs as $memb) {
                    if ($ret['id'] == $memb['userid']) {
                        $name = $memb['namefull'] ? $memb['namefull'] : $memb['nameshort'];
                        $memb['namedisplay'] = $name;
                        $memb['added'] = Utils::ISODate($memb['added']);
                        $memb['id'] = $memb['groupid'];
                        unset($memb['groupid']);

                        if ($memb['lat'] && $memb['lng']) {
                            $box = Utils::presdef('activearea', $ret, NULL);

                            $box = [
                                'swlat' => $box == NULL ? $memb['lat'] : min($memb['lat'], $box['swlat']),
                                'swlng' => $box == NULL ? $memb['lng'] : min($memb['lng'], $box['swlng']),
                                'nelng' => $box == NULL ? $memb['lng'] : max($memb['lng'], $box['nelng']),
                                'nelat' => $box == NULL ? $memb['lat'] : max($memb['lat'], $box['nelat'])
                            ];

                            $ret['activearea'] = $box;

                            if ($box) {
                                $ret['activedistance'] = round(Location::getDistance($box['swlat'], $box['swlng'], $box['nelat'], $box['nelng']));
                            }
                        }

                        $ret['applied'][] = $memb;
                    }
                }
            }
        }
    }

    public function getPublicSpammer(&$rets, $me, $systemrole) {
        # We want to check for spammers.  If we have suitable rights then we can
        # return detailed info; otherwise just that they are on the list.
        #
        # We don't do this for our own logged in user, otherwise we recurse to death.
        $myid = $me ? $me->getId() : NULL;
        $userids = array_filter(array_keys($rets), function($val) use ($myid) {
            return($val != $myid);
        });

        if (count($userids)) {
            # Fetch the users.  There are so many users that there is no point trying to use the query cache.
            $sql = "SELECT * FROM spam_users WHERE userid IN (" . implode(',', $userids) . ");";

            $users = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);

            foreach ($rets as &$ret) {
                foreach ($users as &$user) {
                    if ($user['userid'] == $ret['id']) {
                        if (Session::modtools() && ($systemrole == User::ROLE_MODERATOR ||
                                $systemrole == User::SYSTEMROLE_ADMIN ||
                                $systemrole == User::SYSTEMROLE_SUPPORT)) {
                            $ret['spammer'] = [];
                            foreach (['id', 'userid', 'byuserid', 'added', 'collection', 'reason'] as $att) {
                                $ret['spammer'][$att]= $user[$att];
                            }

                            $ret['spammer']['added'] = Utils::ISODate($ret['spammer']['added']);
                        } else {
                            $ret['spammer'] = TRUE;
                        }
                    }
                }
            }
        }
    }

    public function getEmailHistory(&$rets) {
        $userids = array_keys($rets);

        $emails = $this->dbhr->preQuery("SELECT * FROM logs_emails WHERE userid IN (" . implode(',', $userids) . ");", NULL, FALSE, FALSE);

        foreach ($rets as $retind => $ret) {
            $rets[$retind]['emailhistory'] = [];

            foreach ($emails as $email) {
                if ($rets[$retind]['id'] == $email['userid']) {
                    $email['timestamp'] = Utils::ISODate($email['timestamp']);
                    unset($email['userid']);
                    $rets[$retind]['emailhistory'][] = $email;
                }
            }
        }
    }

    public function getPublicsById($uids, $groupids = NULL, $history = TRUE, $comments = TRUE, $memberof = TRUE, $applied = TRUE, $modmailsonly = FALSE, $emailhistory = FALSE, $msgcoll = [MessageCollection::APPROVED], $historyfull = FALSE) {
        $rets = [];

        # We might have some of these in cache, especially ourselves.
        $uidsleft = [];

        foreach ($uids as $uid) {
            $u = User::get($this->dbhr, $this->dbhm, $uid, TRUE, TRUE);

            if ($u) {
                $rets[$uid] = $u->getPublic($groupids, $history, $comments, $memberof, $applied, $modmailsonly, $emailhistory, $msgcoll, $historyfull);
            } else {
                $uidsleft[] = $uid;
            }
        }

        $uidsleft = array_filter($uidsleft);

        if (count($uidsleft)) {
            $us = $this->dbhr->preQuery("SELECT * FROM users WHERE id IN (" . implode(',', $uidsleft) . ");", NULL, FALSE, FALSE);
            $users = [];
            foreach ($us as $u) {
                $users[$u['id']] = $u;
            }

            if (count($users)) {
                $users = $this->getPublics($users, $groupids, $history, $comments, $memberof, $applied, $modmailsonly, $emailhistory, $msgcoll, $historyfull);

                foreach ($users as $user) {
                    $rets[$user['id']] = $user;
                }
            }
        }

        return($rets);
    }

    public function isTN() {
        return strpos($this->getEmailPreferred(), '@user.trashnothing.com') !== FALSE;
    }

    public function getPublicEmails(&$rets) {
        $userids = array_keys($rets);
        $emails = $this->getEmailsById($userids);

        foreach ($rets as &$ret) {
            if (Utils::pres($ret['id'], $emails)) {
                $ret['emails'] = $emails[$ret['id']];
            }
        }
    }

    public static function purgedUser($id) {
        return [
            'id' => $id,
            'displayname' => 'Purged user #' . $id,
            'systemrole' => User::SYSTEMROLE_USER
        ];
    }

    public function getPublicLogs($me, &$rets, $modmailsonly, &$ctx, $suppress = TRUE, $seeall = FALSE) {
        # Add in the log entries we have for this user.  We exclude some logs of little interest to mods.
        # - creation - either of ourselves or others during syncing.
        # - deletion of users due to syncing
        # Don't cache as there might be a lot, they're rarely used, and it can cause UT issues.
        $myid = $me ? $me->getId() : NULL;
        $uids = array_keys($rets);
        $startq = $ctx ? (" AND id < " . intval($ctx['id']) . " ") : '';
        $modships = $me ? $me->getModeratorships() : [];
        $groupq = count($modships) ? (" AND groupid IN (" . implode(',', $modships) . ")") : '';
        $modmailq = " AND ((type = 'Message' AND subtype IN ('Rejected', 'Deleted', 'Replied')) OR (type = 'User' AND subtype IN ('Mailed', 'Rejected', 'Deleted'))) $groupq";
        $modq = $modmailsonly ? $modmailq : '';
        $suppq = $suppress ? " AND NOT (type = 'User' AND subtype IN('Created', 'Merged', 'YahooConfirmed')) " : '';
        $sql = "SELECT DISTINCT * FROM logs WHERE (user IN (" . implode(',', $uids) . ") OR byuser IN (" . implode(',', $uids) . ")) $startq $suppq $modq ORDER BY id DESC LIMIT 50;";
        $logs = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
        $groups = [];
        $users = [];
        $configs = [];

        # Get all log users in a single go.
        $loguids = array_filter(array_merge(array_column($rets, 'user'), array_column($rets, 'byuser')));

        if (count($loguids)) {
            $u = new User($this->dbhr, $this->dbhm);
            $users = $u->getPublicsById($loguids, NULL, FALSE, FALSE, FALSE, FALSE);
        }

        if (!$ctx) {
            $ctx = ['id' => 0];
        }

        foreach ($rets as $uid => $ret) {
            $rets[$uid]['logs'] = [];

            foreach ($logs as $log) {
                if ($log['user'] == $ret['id'] || $log['byuser'] == $ret['id']) {
                    $ctx['id'] = $ctx['id'] == 0 ? $log['id'] : intval(min($ctx['id'], $log['id']));

                    if (Utils::pres('byuser', $log)) {
                        if (!Utils::pres($log['byuser'], $users)) {
                            $u = User::get($this->dbhr, $this->dbhm, $log['byuser']);

                            if ($u->getId() == $log['byuser']) {
                                $users[$log['byuser']] = $u->getPublic(NULL, FALSE);
                            } else {
                                $users[$log['byuser']] = User::purgedUser($log['byuser']);
                            }
                        }

                        $log['byuser'] = $users[$log['byuser']];
                    }

                    if (Utils::pres('user', $log)) {
                        if (!Utils::pres($log['user'], $users)) {
                            $u = User::get($this->dbhr, $this->dbhm, $log['user']);

                            if ($u->getId() == $log['user']) {
                                $users[$log['user']] = $u->getPublic(NULL, FALSE);
                            } else {
                                $users[$log['user']] = User::purgedUser($log['user']);
                            }
                        }

                        $log['user'] = $users[$log['user']];
                    }

                    if (Utils::pres('groupid', $log)) {
                        if (!Utils::pres($log['groupid'], $groups)) {
                            $g = Group::get($this->dbhr, $this->dbhm, $log['groupid']);

                            if ($g->getId()) {
                                $groups[$log['groupid']] = $g->getPublic();
                                $groups[$log['groupid']]['myrole'] = $me ? $me->getRoleForGroup($log['groupid']) : User::ROLE_NONMEMBER;
                            }
                        }

                        # We can see logs for ourselves.
                        if (!($myid != NULL && Utils::pres('user', $log) && Utils::presdef('id', $log['user'], NULL) == $myid) &&
                            $g->getId() &&
                            $groups[$log['groupid']]['myrole'] != User::ROLE_OWNER &&
                            $groups[$log['groupid']]['myrole'] != User::ROLE_MODERATOR &&
                            !$seeall
                        ) {
                            # We can only see logs for this group if we have a mod role, or if we have appropriate system
                            # rights.  Skip this log.
                            continue;
                        }

                        $log['group'] = Utils::presdef($log['groupid'], $groups, NULL);
                    }

                    if (Utils::pres('configid', $log)) {
                        if (!Utils::pres($log['configid'], $configs)) {
                            $c = new ModConfig($this->dbhr, $this->dbhm, $log['configid']);

                            if ($c->getId()) {
                                $configs[$log['configid']] = $c->getPublic();
                            }
                        }

                        if (Utils::pres($log['configid'], $configs)) {
                            $log['config'] = $configs[$log['configid']];
                        }
                    }

                    if (Utils::pres('stdmsgid', $log)) {
                        $s = new StdMessage($this->dbhr, $this->dbhm, $log['stdmsgid']);
                        $log['stdmsg'] = $s->getPublic();
                    }

                    if (Utils::pres('msgid', $log)) {
                        $m = new Message($this->dbhr, $this->dbhm, $log['msgid']);

                        if ($m->getID()) {
                            $log['message'] = $m->getPublic(FALSE);

                            # If we're a mod (which we must be because we're accessing logs) we need to see the
                            # envelopeto, because this is displayed in MT.  No real privacy issues in that.
                            $log['message']['envelopeto'] = $m->getPrivate('envelopeto');
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

                    $log['timestamp'] = Utils::ISODate($log['timestamp']);

                    $rets[$uid]['logs'][] = $log;
                }
            }
        }

        # Get merge history
        $merges = [];
        do {
            $added = FALSE;
            $sql = "SELECT * FROM logs WHERE type = 'User' AND subtype = 'Merged' AND user IN (" . implode(',', $uids) . ");";
            $logs = $this->dbhr->preQuery($sql);
            foreach ($logs as $log) {
                #error_log("Consider merge log {$log['text']}");
                if (preg_match('/Merged (.*) into (.*?) \((.*)\)/', $log['text'], $matches)) {
                    #error_log("Matched " . var_export($matches, TRUE));
                    #error_log("Check ids {$matches[1]} and {$matches[2]}");
                    foreach ([$matches[1], $matches[2]] as $id) {
                        if (!in_array($id, $uids, TRUE)) {
                            $added = TRUE;
                            $uids[] = $id;
                            $merges[] = ['timestamp' => Utils::ISODate($log['timestamp']), 'from' => $matches[1], 'to' => $matches[2], 'reason' => $matches[3]];
                        }
                    }
                }
            }
        } while ($added);

        $merges = array_unique($merges, SORT_REGULAR);

        foreach ($rets as $uid => $ret) {
            $rets[$uid]['merges'] = [];

            foreach ($merges as $merge) {
                if ($merge['from'] == $ret['id'] || $merge['to'] == $ret['id']) {
                    $rets[$uid]['merges'][] = $merge;
                }
            }
        }
    }

    public function getPublics($users, $groupids = NULL, $history = TRUE, $comments = TRUE, $memberof = TRUE, $applied = TRUE, $modmailsonly = FALSE, $emailhistory = FALSE, $msgcoll = [MessageCollection::APPROVED], $historyfull = FALSE)
    {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $systemrole = $me ? $me->getPrivate('systemrole') : User::SYSTEMROLE_USER;
        $freeglemod = $me && $me->isFreegleMod();

        $rets = [];

        $this->getPublicAtts($rets, $users, $me);
        $this->getPublicProfiles($rets);
        $this->getSupporters($rets);

        if ($systemrole == User::ROLE_MODERATOR || $systemrole == User::SYSTEMROLE_ADMIN || $systemrole == User::SYSTEMROLE_SUPPORT) {
            $this->getPublicEmails($rets);
        }

        if ($history) {
            $this->getPublicHistory($me, $rets, $users, $groupids, $historyfull, $systemrole, $msgcoll);
        }

        if (Session::modtools()) {
            $this->getPublicMemberOf($rets, $me, $freeglemod, $memberof, $systemrole);
            $this->getPublicApplied($rets, $me, $freeglemod, $applied, $systemrole);
            $this->getPublicSpammer($rets, $me, $systemrole);

            if ($comments) {
                $this->getComments($me, $rets);
            }

            if ($emailhistory) {
                $this->getEmailHistory($rets);
            }
        }

        return ($rets);
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

    public function merge($id1, $id2, $reason, $forcemerge = FALSE)
    {
        error_log("Merge $id1, $id2, $reason");

        # We might not be able to merge them, if one or the other has the setting to prevent that.
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $ret = FALSE;

        if ($id1 != $id2 && (($u1->canMerge() && $u2->canMerge()) || ($forcemerge))) {
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
            $me = Session::whoAmI($this->dbhr, $this->dbhm);

            $rc = $this->dbhm->beginTransaction();
            $rollback = FALSE;

            if ($rc) {
                try {
                    #error_log("Started transaction");
                    $rollback = TRUE;

                    # Merge the top-level memberships
                    $id2membs = $this->dbhr->preQuery("SELECT * FROM memberships WHERE userid = $id2;");
                    foreach ($id2membs as $id2memb) {
                        $rc2 = $rc;
                        # Jiggery-pokery with $rc for UT purposes.
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
                                #error_log("Check {$id2memb['groupid']} memb $id2 $key = " . Utils::presdef($key, $id2memb, NULL));
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
                        $this->dbhm->preExec("UPDATE IGNORE messages_promises SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE messages_by SET userid = $id1 WHERE userid = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE trysts SET user1 = $id1 WHERE user1 = $id2;");
                        $this->dbhm->preExec("UPDATE IGNORE trysts SET user2 = $id1 WHERE user2 = $id2;");

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
                    foreach (['fullname', 'firstname', 'lastname', 'yahooid'] as $att) {
                        $users = $this->dbhm->preQuery("SELECT $att FROM users WHERE id = $id2;");
                        foreach ($users as $user) {
                            $this->dbhm->preExec("UPDATE users SET $att = NULL WHERE id = $id2;");
                            User::clearCache($id1);
                            User::clearCache($id2);

                            if (!$u1->getPrivate($att)) {
                                if ($att != 'fullname') {
                                    $this->dbhm->preExec("UPDATE users SET $att = ? WHERE id = $id1 AND $att IS NULL;", [$user[$att]]);
                                } else if (stripos($user[$att], 'fbuser') === FALSE && stripos($user[$att], '-owner') === FALSE) {
                                    # We don't want to overwrite a name with FBUser or a -owner address.
                                    $this->dbhm->preExec("UPDATE users SET $att = ? WHERE id = $id1;", [$user[$att]]);
                                }
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
                    }

                    if ($rc) {
                        # Everything worked.
                        $rollback = FALSE;

                        # We might have merged ourself!
                        if (Utils::pres('id', $_SESSION) == $id2) {
                            $_SESSION['id'] = $id1;
                        }
                    }
                } catch (\Exception $e) {
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

                if ($ret) {
                    # Finally, delete id2.  We used to this inside the transaction, but the result was that
                    # fromuser sometimes got set to NULL on messages owned by id2, despite them having been set to
                    # id1 earlier on.  Either we're dumb, or there's a subtle interaction between transactions,
                    # foreign keys and Percona clusters.  This is safer and proves to be more reliable.
                    #
                    # Make sure we don't pick up an old cached version, as we've just changed it quite a bit.
                    error_log("Merged $id1 < $id2, $reason");
                    $deleteme = new User($this->dbhm, $this->dbhm, $id2);
                    $rc = $deleteme->delete(NULL, NULL, NULL, FALSE);
                }
            }
        }

        return ($ret);
    }

    public function mailer($user, $modmail, $toname, $to, $bcc, $fromname, $from, $subject, $text) {
        # These mails don't need tracking, so we don't call addHeaders.
        try {
            #error_log(session_id() . " mail " . microtime(true));

            list ($transport, $mailer) = Mail::getMailer();

            $message = \Swift_Message::newInstance()
                ->setSubject($subject)
                ->setFrom([$from => $fromname])
                ->setTo([$to => $toname])
                ->setBody($text);

            # We add some headers so that if we receive this back, we can identify it as a mod mail.
            $headers = $message->getHeaders();

            if ($user) {
                $headers->addTextHeader('X-Iznik-From-User', $user->getId());
            }

            $headers->addTextHeader('X-Iznik-ModMail', $modmail);

            if ($bcc) {
                $message->setBcc(explode(',', $bcc));
            }

            $this->sendIt($mailer, $message);

            # Stop the transport, otherwise the message doesn't get sent until the UT script finishes.
            $transport->stop();

            #error_log(session_id() . " mailed " . microtime(true));
        } catch (\Exception $e) {
            # Not much we can do - shouldn't really happen given the failover transport.
            // @codeCoverageIgnoreStart
            error_log("Send failed with " . $e->getMessage());
            // @codeCoverageIgnoreEnd
        }
    }

    private function maybeMail($groupid, $subject, $body, $action)
    {
        if ($body) {
            # We have a mail to send.
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = $me->getId();

            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $atts = $g->getPublic();

            $me = Session::whoAmI($this->dbhr, $this->dbhm);

            # Find who to send it from.  If we have a config to use for this group then it will tell us.
            $name = $me->getName();
            $c = new ModConfig($this->dbhr, $this->dbhm);
            $cid = $c->getForGroup($me->getId(), $groupid);
            $c = new ModConfig($this->dbhr, $this->dbhm, $cid);
            $fromname = $c->getPrivate('fromname');
            $name = ($fromname == 'Groupname Moderator') ? '$groupname Moderator' : $name;

            # We can do a simple substitution in the from name.
            $name = str_replace('$groupname', $atts['namedisplay'], $name);

            $bcc = $c->getBcc($action);

            if ($bcc) {
                $bcc = str_replace('$groupname', $atts['nameshort'], $bcc);
            }

            # We add the message into chat.
            $r = new ChatRoom($this->dbhr, $this->dbhm);
            $rid = $r->createUser2Mod($this->id, $groupid);
            $m = NULL;

            $to = $this->getEmailPreferred();

            if ($rid) {
                # Create the message.  Mark it as needing review to prevent timing window.
                $m = new ChatMessage($this->dbhr, $this->dbhm);
                list ($mid, $banned) = $m->create($rid,
                    $myid,
                    "$subject\r\n\r\n$body",
                    ChatMessage::TYPE_MODMAIL,
                    NULL,
                    TRUE,
                    NULL,
                    NULL,
                    NULL,
                    NULL,
                    NULL,
                    TRUE,
                    TRUE);

                $this->mailer($me, TRUE, $this->getName(), $bcc, NULL, $name, $g->getModsEmail(), $subject, "(This is a BCC of a message sent to Freegle user #" . $this->id . " $to)\n\n" . $body);
            }

            if ($to && !Mail::ourDomain($to)) {
                # For users who we host, we leave the message unseen; that will then later generate a notification
                # to them.  Otherwise we mail them the message and mark it as seen, because they would get
                # confused by a mail in our notification format.
                $this->mailer($me, TRUE, $this->getName(), $to, NULL, $name, $g->getModsEmail(), $subject, $body);

                # We've mailed the message out so they are up to date with this chat.
                $r->upToDate($this->id);
            }

            if ($m) {
                # Allow mailing to happen.
                $m->setPrivate('reviewrequired', 0);

                # We, as a mod, have seen this message - update the roster to show that.  This avoids this message
                # appearing as unread to us.
                $r->updateRoster($myid, $mid);

                # Ensure that the other mods are present in the roster with the message seen/unseen depending on
                # whether that's what we want.
                $mods = $g->getMods();
                foreach ($mods as $mod) {
                    if ($mod != $myid) {
                        if ($c->getPrivate('chatread')) {
                            # We want to mark it as seen for all mods.
                            $r->updateRoster($mod, $mid, ChatRoom::STATUS_AWAY);
                        } else {
                            # Leave it unseen, but make sure they're in the roster.
                            $r->updateRoster($mod, NULL, ChatRoom::STATUS_AWAY);
                        }
                    }
                }

                if ($c->getPrivate('chatread')) {
                    $m->setPrivate('mailedtoall', 1);
                    $m->setPrivate('seenbyall', 1);
                }
            }
        }
    }

    public function mail($groupid, $subject, $body, $stdmsgid, $action = NULL)
    {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

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

    public function happinessReviewed($happinessid) {
        $this->dbhm->preExec("UPDATE messages_outcomes SET reviewed = 1 WHERE id = ?", [
            $happinessid
        ]);
    }

    public function getCommentsForSingleUser($userid) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $rets = [
            $userid => [
                'id' => $userid
            ]
        ];

        $this->getComments($me, $rets);

        return $rets[$userid]['comments'];
    }

    public function getComments($me, &$rets)
    {
        $userids = array_keys($rets);

        if ($me && $me->isModerator()) {
            # Generally there will be no or few comments.  It's quicker (because of indexing) to get them all and filter
            # by groupid than it is to construct a query which includes groupid.  Likewise it's not really worth
            # optimising the calls for byuser, since there won't be any for most users.
            $sql = "SELECT * FROM users_comments WHERE userid IN (" . implode(',', $userids) . ") ORDER BY date DESC;";
            $comments = $this->dbhr->preQuery($sql, [$this->id]);
            #error_log("Got comments " . var_export($comments, TRUE));

            $commentuids = [];
            foreach ($comments as $comment) {
                if (Utils::pres('byuserid', $comment)) {
                    $commentuids[] = $comment['byuserid'];
                }
            }

            $commentusers = [];

            if ($commentuids && count($commentuids)) {
                $commentusers = $this->getPublicsById($commentuids, NULL, FALSE, FALSE);

                foreach ($commentusers as &$commentuser) {
                    $commentuser['settings'] = NULL;
                }
            }

            foreach ($rets as $retind => $ret) {
                $rets[$retind]['comments'] = [];

                for ($commentind = 0; $commentind < count($comments); $commentind++) {
                    if ($comments[$commentind]['userid'] == $rets[$retind]['id']) {
                        $comments[$commentind]['date'] = Utils::ISODate($comments[$commentind]['date']);
                        $comments[$commentind]['reviewed'] = Utils::ISODate($comments[$commentind]['reviewed']);

                        if (Utils::pres('byuserid', $comments[$commentind])) {
                            $comments[$commentind]['byuser'] = $commentusers[$comments[$commentind]['byuserid']];
                        }

                        $rets[$retind]['comments'][] = $comments[$commentind];
                    }
                }
            }
        }
    }

    public function listComments(&$ctx) {
        $ctxq = '';

        if ($ctx) {
            $ctxq = "users_comments.id > " . intval(Utils::presdef('id', $ctx, NULL)) . " AND ";
        }

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $groupids = $me->getModeratorships();

        $sql = "SELECT * FROM users_comments WHERE $ctxq groupid IN (" . implode(',', $groupids) . ") ORDER BY reviewed ASC LIMIT 10;";
        $comments = $this->dbhr->preQuery($sql);

        $uids = array_unique(array_merge(array_column($comments, 'byuserid'), array_column($comments, 'userid')));
        $u = new User($this->dbhr, $this->dbhm);
        $users = $u->getPublicsById($uids, NULL, FALSE, FALSE, FALSE, FALSE);

        foreach ($comments as &$comment) {
            $comment['date'] = Utils::ISODate($comment['date']);
            $comment['reviewed'] = Utils::ISODate($comment['reviewed']);

            if (Utils::pres('userid', $comment)) {
                $comment['user'] = $users[$comment['userid']];
                unset($comment['userid']);
            }

            if (Utils::pres('byuserid', $comment)) {
                $comment['byuser'] = $users[$comment['byuserid']];
                unset($comment['byuserid']);
            }

            $ctx['id'] = $comment['id'];
        }

        return $comments;
    }

    public function getComment($id)
    {
        # We can only see comments on groups on which we have mod status.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $groupids = $me ? $me->getModeratorships() : [];
        $groupids = count($groupids) == 0 ? [0] : $groupids;

        $sql = "SELECT * FROM users_comments WHERE id = ? AND groupid IN (" . implode(',', $groupids) . ") ORDER BY date DESC;";
        $comments = $this->dbhr->preQuery($sql, [$id]);

        foreach ($comments as &$comment) {
            $comment['date'] = Utils::ISODate($comment['date']);
            $comment['reviewed'] = Utils::ISODate($comment['reviewed']);

            if (Utils::pres('byuserid', $comment)) {
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
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        # By any supplied user else logged in user if any.
        $byuserid = $byuserid ? $byuserid : ($me ? $me->getId() : NULL);

        # Can only add comments for a group on which we're a mod, or if we are Support adding a global comment.
        $rc = NULL;
        $groups = $checkperms ? ($me ? $me->getModeratorships() : [0]) : [$groupid];
        $added = FALSE;

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

                $added = TRUE;
            }
        }

        if (!$added && $me->isAdminOrSupport()) {
            $rc = NULL;
            $sql = "INSERT INTO users_comments (userid, groupid, byuserid, user1, user2, user3, user4, user5, user6, user7, user8, user9, user10, user11) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                NULL,
                $byuserid,
                $user1, $user2, $user3, $user4, $user5, $user6, $user7, $user8, $user9, $user10, $user11
            ]);

            $rc = $this->dbhm->lastInsertId();
        }

        return ($rc);
    }

    public function editComment($id, $user1 = NULL, $user2 = NULL, $user3 = NULL, $user4 = NULL, $user5 = NULL,
                                $user6 = NULL, $user7 = NULL, $user8 = NULL, $user9 = NULL, $user10 = NULL,
                                $user11 = NULL)
    {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        # Update to logged in user if any.
        $byuserid = $me ? $me->getId() : NULL;

        # Can only edit comments for a group on which we're a mod.  This code isn't that efficient but it doesn't
        # happen often.
        $rc = NULL;
        $comments = $this->dbhr->preQuery("SELECT id, groupid FROM users_comments WHERE id = ?;", [
            $id
        ]);

        foreach ($comments as $comment) {
            if ($me && ($me->isAdminOrSupport() || $me->isModOrOwner($comment['groupid']))) {
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
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        # Can only delete comments for a group on which we're a mod.
        $rc = FALSE;

        $comments = $this->dbhr->preQuery("SELECT id, groupid FROM users_comments WHERE id = ?;", [
            $id
        ]);

        foreach ($comments as $comment) {
            if ($me && ($me->isAdminOrSupport() || $me->isModOrOwner($comment['groupid']))) {
                $rc = $this->dbhm->preExec("DELETE FROM users_comments WHERE id = ?;", [$id]);
            }
        }

        return ($rc);
    }

    public function deleteComments()
    {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

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
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $l = new Log($this->dbhr, $this->dbhm);
        if ($email) {
            $this->removeEmail($email);
        }

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
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        $html = $twig->render('welcome/welcome.html', [
            'email' => $email,
            'password' => $password
        ]);

        $message = \Swift_Message::newInstance()
            ->setSubject("Welcome to " . SITE_NAME . "!")
            ->setFrom([NOREPLY_ADDR => SITE_NAME])
            ->setTo($email)
            ->setBody("Thanks for joining" . SITE_NAME . "!" . ($password ? "  Here's your password: $password." : ''));

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        Mail::addHeaders($message, Mail::WELCOME, $this->getId());

        list ($transport, $mailer) = Mail::getMailer();
        $this->sendIt($mailer, $message);
    }

    public function forgotPassword($email)
    {
        $link = $this->loginLink(USER_SITE, $this->id, '/settings', User::SRC_FORGOT_PASSWORD, TRUE);
        $html = forgot_password(USER_SITE, USERLOGO, $email, $link);

        $message = \Swift_Message::newInstance()
            ->setSubject("Forgot your password?")
            ->setFrom([NOREPLY_ADDR => SITE_NAME])
            ->setTo($email)
            ->setBody("To set a new password, just log in here: $link");

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        Mail::addHeaders($message, Mail::FORGOT_PASSWORD, $this->getId());

        list ($transport, $mailer) = Mail::getMailer();
        $this->sendIt($mailer, $message);
    }

    public function verifyEmail($email, $force = false)
    {
        # If this is one of our current emails, then we can just make it the primary.
        $emails = $this->getEmails();
        $handled = FALSE;

        if (!$force) {
            foreach ($emails as $anemail) {
                if ($anemail['email'] == $email) {
                    # It's one of ours already; make sure it's flagged as primary.
                    $this->addEmail($email, 1);
                    $handled = TRUE;
                }
            }
        }

        if (!$handled) {
            # This email is new to this user.  It may or may not currently be in use for another user.  Either
            # way we want to send a verification mail.
            $usersite = strpos($_SERVER['HTTP_HOST'], USER_SITE) !== FALSE || strpos($_SERVER['HTTP_HOST'], 'fdapi') !== FALSE;
            $headers = "From: " . SITE_NAME . " <" . NOREPLY_ADDR . ">\nContent-Type: multipart/alternative; boundary=\"_I_Z_N_I_K_\"\nMIME-Version: 1.0";
            $canon = User::canonMail($email);

            do {
                # Loop in case of clash on the key we happen to invent.
                $key = uniqid();
                $sql = "INSERT INTO users_emails (email, canon, validatekey, backwards) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE validatekey = ?;";
                $this->dbhm->preExec($sql,
                    [$email, $canon, $key, strrev($canon), $key]);
            } while (!$this->dbhm->rowsAffected());

            $confirm = $this->loginLink($usersite ? USER_SITE : MOD_SITE, $this->id, ($usersite ? "/settings/confirmmail/" : "/modtools/settings/confirmmail/") . urlencode($key), 'changeemail', TRUE);

            list ($transport, $mailer) = Mail::getMailer();
            $html = verify_email($email, $confirm, $usersite ? USERLOGO : MODLOGO);

            $message = \Swift_Message::newInstance()
                ->setSubject("Please verify your email")
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setReturnPath($this->getBounce())
                ->setTo([$email => $this->getName()])
                ->setBody("Someone, probably you, has said that $email is their email address.\n\nIf this was you, please click on the link below to verify the address; if this wasn't you, please just ignore this mail.\n\n$confirm");

            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
            # Outlook.
            $htmlPart = \Swift_MimePart::newInstance();
            $htmlPart->setCharset('utf-8');
            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
            $htmlPart->setContentType('text/html');
            $htmlPart->setBody($html);
            $message->attach($htmlPart);

            Mail::addHeaders($message, Mail::VERIFY_EMAIL, $this->getId());

            $this->sendIt($mailer, $message);
        }

        return ($handled);
    }

    public function confirmEmail($key)
    {
        $rc = FALSE;
        $sql = "SELECT * FROM users_emails WHERE validatekey = ?;";
        $mails = $this->dbhr->preQuery($sql, [$key]);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

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

    public function confirmUnsubscribe()
    {
        list ($transport, $mailer) = Mail::getMailer();

        $link = $this->getUnsubLink(USER_SITE, $this->id, NULL, TRUE) . "&confirm=1";

        $message = \Swift_Message::newInstance()
            ->setSubject("Please confirm you want to leave Freegle")
            ->setFrom(NOREPLY_ADDR)
            ->setReplyTo(SUPPORT_ADDR)
            ->setTo($this->getEmailPreferred())
            ->setDate(time())
            ->setBody("Please click here to leave Freegle:\r\n\r\n$link\r\n\r\nIf you didn't try to leave, please ignore this mail.\r\n\r\nThanks for freegling, and do please come back in the future.");

        Mail::addHeaders($message, Mail::UNSUBSCRIBE);
        $this->sendIt($mailer, $message);
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

            if (!$force && strlen(str_replace(' ', '', $yahooid)) && strpos($yahooid, '@') === FALSE && strlen($yahooid) <= 16) {
                $email = str_replace(' ', '', $yahooid) . '-' . $this->id . '@' . USER_DOMAIN;
            } else {
                # Their own email might already be of that nature, which would be lovely.
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
                    $email = str_replace(' ', '', $p > 0 ? substr($email, 0, $p) : $email) . '-' . $this->id . '@' . USER_DOMAIN;
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
                                $word = trim($word);
                                if (strlen($word)) {
                                    $p = stripos($email, $word);
                                    $q = strpos($email, '@');

                                    if ($word !== '-') {
                                        # Dash is always present, which is fine.
                                        $email = ($p !== FALSE && $p < $q) ? NULL : $email;
                                    }
                                }
                            }
                        }
                    } while (!$email);
                }
            }
        }

        return ($email);
    }

    public function delete($groupid = NULL, $subject = NULL, $body = NULL, $log = TRUE)
    {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

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

    public function getUnsubLink($domain, $id, $type = NULL, $auto = FALSE)
    {
        return (User::loginLink($domain, $id, "/unsubscribe/$id", $type, $auto));
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
                $key = Utils::randstr(32);
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
        # We don't want to send emails to people who haven't been active for more than six months.  This improves
        # our spam reputation, by avoiding honeytraps.
        $sendit = FALSE;
        $lastaccess = strtotime($this->getPrivate('lastaccess'));

        // This time is also present on the client in ModMember, and in Engage.
        if (time() - $lastaccess <= Engage::USER_INACTIVE) {
            $sendit = TRUE;

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
        }

        #error_log("Sendit? $sendit");
        return ($sendit);
    }

    public function getMembershipHistory()
    {
        # We get this from our logs.
        $sql = "SELECT * FROM logs WHERE user = ? AND `type` = ? ORDER BY id DESC;";
        $logs = $this->dbhr->preQuery($sql, [$this->id, Log::TYPE_GROUP]);

        $ret = [];
        foreach ($logs as $log) {
            $thisone = NULL;
            switch ($log['subtype']) {
                case Log::SUBTYPE_JOINED:
                case Log::SUBTYPE_APPROVED:
                case Log::SUBTYPE_REJECTED:
                case Log::SUBTYPE_APPLIED:
                case Log::SUBTYPE_LEFT:
                    {
                        $thisone = $log['subtype'];
                        break;
                    }
            }

            #error_log("{$log['subtype']} gives $thisone {$log['groupid']}");
            if ($thisone && $log['groupid']) {
                $g = Group::get($this->dbhr, $this->dbhm, $log['groupid']);

                if ($g->getId() === $log['groupid']) {
                    $ret[] = [
                        'timestamp' => Utils::ISODate($log['timestamp']),
                        'type' => $thisone,
                        'group' => [
                            'id' => $log['groupid'],
                            'nameshort' => $g->getPrivate('nameshort'),
                            'namedisplay' => $g->getName()
                        ],
                        'text' => $log['text']
                    ];
                }
            }
        }

        return ($ret);
    }

    public function search($search, $ctx)
    {
        if (preg_replace('/\-|\~/', '', $search) === '') {
            # Most likely an encoded id.
            $search = User::decodeId($search);
        }

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $id = intval(Utils::presdef('id', $ctx, 0));
        $ctx = $ctx ? $ctx : [];
        $q = $this->dbhr->quote("$search%");
        $backwards = strrev($search);
        $qb = $this->dbhr->quote("$backwards%");

        $canon = $this->dbhr->quote(User::canonMail($search) . "%");

        # If we're searching for a notify address, switch to the user it.
        $search = preg_match('/notify-(.*)-(.*)' . USER_DOMAIN . '/', $search, $matches) ? $matches[2] : $search;

        $sql = "SELECT DISTINCT userid FROM
                ((SELECT userid FROM users_emails WHERE canon LIKE $canon OR backwards LIKE $qb) UNION
                (SELECT id AS userid FROM users WHERE fullname LIKE $q) UNION
                (SELECT id AS userid FROM users WHERE yahooid LIKE $q) UNION
                (SELECT id AS userid FROM users WHERE id = ?) UNION
                (SELECT userid FROM users_logins WHERE uid LIKE $q)) t WHERE userid > ? ORDER BY userid ASC";
        $users = $this->dbhr->preQuery($sql, [$search, $id]);

        $ret = [];

        foreach ($users as $user) {
            $ctx['id'] = $user['userid'];

            $u = User::get($this->dbhr, $this->dbhm, $user['userid']);

            $thisone = $u->getPublic(NULL, TRUE, TRUE, TRUE, TRUE, FALSE, TRUE, [
                MessageCollection::PENDING,
                MessageCollection::APPROVED,
                MessageCollection::SPAM
            ], TRUE);

            # We might not have the emails.
            $thisone['email'] = $u->getEmailPreferred();
            $thisone['emails'] = $u->getEmails();

            $thisone['membershiphistory'] = $u->getMembershipHistory();

            # Make sure there's a link login as admin/support can use that to impersonate.
            if ($me && ($me->isAdmin() || ($me->isAdminOrSupport() && !$u->isModerator()))) {
                $thisone['loginlink'] = $u->loginLink(USER_SITE, $user['userid'], '/', NULL, TRUE);
            }

            $thisone['logins'] = $u->getLogins($me && $me->isAdmin());

            # Also return the chats for this user.  Can't use ChatRooms::listForUser because that would exclude any
            # chats on groups where we were no longer a member.
            $rooms = array_filter(array_column($this->dbhr->preQuery("SELECT id FROM chat_rooms WHERE user1 = ? UNION SELECT id FROM chat_rooms WHERE chattype = ? AND user2 = ?;", [
                $user['userid'],
                ChatRoom::TYPE_USER2USER,
                $user['userid'],
            ]), 'id'));

            $thisone['chatrooms'] = [];

            if ($rooms) {
                $r = new ChatRoom($this->dbhr, $this->dbhm);
                $thisone['chatrooms'] = $r->fetchRooms($rooms, $user['userid'], FALSE);
            }

            # Add the public location and best guess lat/lng
            $thisone['info']['publiclocation'] = $u->getPublicLocation();
            $latlng = $u->getLatLng();
            $thisone['privateposition'] = [
                'lat' => $latlng[0],
                'lng' => $latlng[1],
                'name' => $latlng[2]
            ];

            $thisone['comments'] = $this->getCommentsForSingleUser($user['userid']);

            $push = $this->dbhr->preQuery("SELECT MAX(lastsent) AS lastpush FROM users_push_notifications WHERE userid = ?;", [
                $user['userid']
            ]);

            foreach ($push as $p) {
                $thisone['lastpush'] = Utils::ISODate($p['lastpush']);
            }

            $thisone['info'] = $u->getInfo();
            $thisone['trustlevel'] = $u->getPrivate('trustlevel');

            $ret[] = $thisone;
        }

        return ($ret);
    }

    private function safeGetPostcode($val) {
        $ret = NULL;

        $settings = json_decode($val, TRUE);

        if (Utils::pres('mylocation', $settings) &&
            Utils::presdef('type', $settings['mylocation'], NULL) == 'Postcode') {
            $ret = Utils::presdef('name', $settings['mylocation'], NULL);
        }

        return $ret;
    }

    public function setPrivate($att, $val)
    {
        if (!strcmp($att, 'settings') && $val) {
            # Possible location change.
            $oldloc = $this->safeGetPostcode($this->getPrivate('settings'));
            $newloc = $this->safeGetPostcode($val);

            if ($oldloc !== $newloc) {
                $this->log->log([
                            'type' => Log::TYPE_USER,
                            'subtype' => Log::SUBTYPE_POSTCODECHANGE,
                            'user' => $this->id,
                            'text' => $newloc
                        ]);
            }
        }

        User::clearCache($this->id);
        parent::setPrivate($att, $val);

    }

    public function canMerge()
    {
        $settings = Utils::pres('settings', $this->user) ? json_decode($this->user['settings'], TRUE) : [];
        return (array_key_exists('canmerge', $settings) ? $settings['canmerge'] : TRUE);
    }

    public function notifsOn($type, $groupid = NULL)
    {
        $settings = Utils::pres('settings', $this->user) ? json_decode($this->user['settings'], TRUE) : [];
        $notifs = Utils::pres('notifications', $settings);

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

        #error_log("Notifs on for user #{$this->id} type $type ? $ret from " . var_export($notifs, TRUE));
        return ($ret);
    }

    public function getNotificationPayload($modtools)
    {
        # This gets a notification count/title/message for this user.
        $notifcount = 0;
        $title = '';
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
                    $message = Utils::presdef('message', $msgs[count($msgs) - 1], "You have a message");
                    $message = strlen($message) > 256 ? (substr($message, 0, 256) . "...") : $message;
                }

                $route = "/chats/" . $unseen[0]['chatid'];

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
                    $ctx = NULL;
                    $notifs = $n->get($this->id, $ctx);
                    $title = $n->getNotifTitle($notifs);
                    $route = '/';

                    if (count($notifs) > 0) {
                        # For newsfeed notifications sent a route to the right place.
                        switch ($notifs[0]['type']) {
                            case Notifications::TYPE_COMMENT_ON_COMMENT:
                            case Notifications::TYPE_COMMENT_ON_YOUR_POST:
                            case Notifications::TYPE_LOVED_COMMENT:
                            case Notifications::TYPE_LOVED_POST:
                                $route = '/chitchat/' . $notifs[0]['newsfeedid'];
                                break;
                        }
                    }
                }
            }
        } else {
            # ModTools notification.  We show the count of work + chats.
            $r = new ChatRoom($this->dbhr, $this->dbhm);
            $unseen = $r->allUnseenForUser($this->id, [ChatRoom::TYPE_MOD2MOD, ChatRoom::TYPE_USER2MOD], $modtools);
            $chatcount = count($unseen);

            $work = $this->getWorkCounts();
            $total = $work['total'] + $chatcount;

            // The order of these is important as the route will be the last matching.
            $types = [
                'pendingvolunteering' => [ 'volunteer op', 'volunteerops', '/modtools/volunteering' ],
                'pendingevents' => [ 'event', 'events', '/modtools/communityevents' ],
                'socialactions' => [ 'publicity item', 'publicity items', '/modtools/publicity' ],
                'stories' => [ 'story', 'stories', '/modtools/members/stories' ],
                'newsletterstories' => [ 'newsletter story', 'newsletter stories', '/modtools/members/newsletter' ],
                'chatreview' => [ 'chat message to review', 'chat messages to review', '/modtools/chats/review' ],
                'pendingadmins' => [ 'admin', 'admins', '/modtools/admins' ],
                'spammembers' => [ 'member to review', 'members to review', '/modtools/members/review' ],
                'relatedmembers' => [ 'related member to review', 'related members to review', '/modtools/members/related' ],
                'editreview' => [ 'edit', 'edits', '/modtools/messages/review' ],
                'spam' => [ 'message to review', 'messages to review', '/modtools/messages/review' ],
                'pending' => [ 'pending message', 'pending messages', '/modtools/messages/pending' ]
            ];

            $title = '';
            $route = NULL;

            foreach ($types as $type => $vals) {
                if (Utils::presdef($type, $work, 0) > 0) {
                    $title .= $work[$type] . ' ' . ($work[$type] != 1 ? $vals[1] : $vals[0] ) . "\n";
                    $route = $vals[2];
                }
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
        try {
            $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/donations');
            $twig = new \Twig_Environment($loader);
            list ($transport, $mailer) = Mail::getMailer();

            $message = \Swift_Message::newInstance()
                ->setSubject("Thank you for supporting Freegle!")
                ->setFrom(PAYPAL_THANKS_FROM)
                ->setReplyTo(PAYPAL_THANKS_FROM)
                ->setTo($this->getEmailPreferred())
                ->setBody("Thank you for supporting Freegle!");

            Mail::addHeaders($message, Mail::THANK_DONATION);

            $html = $twig->render('thank.html', [
                'name' => $this->getName(),
                'email' => $this->getEmailPreferred(),
                'unsubscribe' => $this->loginLink(USER_SITE, $this->getId(), "/unsubscribe", NULL)
            ]);

            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
            # Outlook.
            $htmlPart = \Swift_MimePart::newInstance();
            $htmlPart->setCharset('utf-8');
            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
            $htmlPart->setContentType('text/html');
            $htmlPart->setBody($html);
            $message->attach($htmlPart);

            Mail::addHeaders($message, Mail::THANK_DONATION, $this->getId());

            $this->sendIt($mailer, $message);
        } catch (\Exception $e) { error_log("Failed " . $e->getMessage()); };
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

                        list ($transport, $mailer) = Mail::getMailer();
                        $message = \Swift_Message::newInstance()
                            ->setSubject("$fromname has invited you to try Freegle!")
                            ->setFrom([NOREPLY_ADDR => SITE_NAME])
                            ->setReplyTo($frommail)
                            ->setTo($email)
                            ->setBody("$fromname ($email) thinks you might like Freegle, which helps you give and get things for free near you.  Click $url to try it.");

                        Mail::addHeaders($message, Mail::INVITATION);

                        $html = invite($fromname, $frommail, $url);

                        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                        # Outlook.
                        $htmlPart = \Swift_MimePart::newInstance();
                        $htmlPart->setCharset('utf-8');
                        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                        $htmlPart->setContentType('text/html');
                        $htmlPart->setBody($html);
                        $message->attach($htmlPart);

                        $this->sendIt($mailer, $message);
                        $ret = TRUE;

                        $this->dbhm->preExec("UPDATE users SET invitesleft = invitesleft - 1 WHERE id = ?;", [
                            $this->id
                        ]);
                    } catch (\Exception $e) {
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

    public function listInvitations($since = "30 days ago")
    {
        $ret = [];

        # Don't show old invitations - unaccepted ones could languish for ages.
        $mysqltime = date('Y-m-d', strtotime($since));
        $invites = $this->dbhr->preQuery("SELECT id, email, date, outcome, outcometimestamp FROM users_invitations WHERE userid = ? AND date > '$mysqltime';", [
            $this->id
        ]);

        foreach ($invites as $invite) {
            # Check if this email is now on the platform.
            $invite['date'] = Utils::ISODate($invite['date']);
            $invite['outcometimestamp'] = $invite['outcometimestamp'] ? Utils::ISODate($invite['outcometimestamp']) : NULL;
            $ret[] = $invite;
        }

        return ($ret);
    }

    public function getLatLng($usedef = TRUE, $usegroup = TRUE, $blur = self::BLUR_NONE)
    {
        $ret = [ 0, 0, NULL ];

        if ($this->id) {
            $locs = $this->getLatLngs([ $this->user ], $usedef, $usegroup, FALSE, [ $this->user ]);
            $loc = $locs[$this->id];

            if ($blur && ($loc['lat'] || $loc['lng'])) {
                $loc['lat'] = round($loc['lat'], $blur);
                $loc['lng'] = round($loc['lng'], $blur);
            }

            $ret = [ $loc['lat'], $loc['lng'], Utils::presdef('loc', $loc, NULL) ];
        }

        return $ret;
    }

    public function getPublicLocations(&$users, $atts = NULL)
    {
        $userids = array_filter(array_column($users, 'id'));
        $areas = NULL;
        $groups = NULL;
        $membs = NULL;

        if ($userids && count($userids)) {
            # First try to get the location from settings or last location.
            $atts = $atts ? $atts : $this->dbhr->preQuery("SELECT id, settings, lastlocation FROM users WHERE id in (" . implode(',', $userids) . ");", NULL, FALSE, FALSE);

            foreach ($atts as $att) {
                $loc = NULL;
                $grp = NULL;

                $aid = NULL;
                $lid = NULL;
                $lat = NULL;
                $lng = NULL;

                # Default to nowhere.
                $users[$att['id']]['info']['publiclocation'] = [
                    'display' => '',
                    'location' => NULL,
                    'groupname' => NULL
                ];

                if (Utils::pres('settings', $att)) {
                    $settings = $att['settings'];
                    $settings = json_decode($settings, TRUE);

                    if (Utils::pres('mylocation', $settings) && Utils::pres('area', $settings['mylocation'])) {
                        $loc = $settings['mylocation']['area']['name'];
                        $lid = $settings['mylocation']['id'];
                        $lat = $settings['mylocation']['lat'];
                        $lng = $settings['mylocation']['lng'];
                    }
                }

                if (!$loc) {
                    # Get the name of the last area we used.
                    if ($areas === NULL) {
                        $areas = $this->dbhr->preQuery("SELECT l2.id, l2.name, l2.lat, l2.lng, users.id AS userid FROM locations l1 
                            INNER JOIN users ON users.lastlocation = l1.id
                            INNER JOIN locations l2 ON l2.id = l1.areaid
                            WHERE users.id IN (" . implode(',', $userids) . ");", NULL, FALSE, FALSE);
                    }

                    foreach ($areas as $area) {
                        if ($att['id'] === $area['userid']) {
                            $loc = $area['name'];
                            $lid = $area['id'];
                            $lat = $area['lat'];
                            $lng = $area['lng'];
                        }
                    }
                }

                if ($lid) {
                    # Find the group of which we are a member which is closest to our location.  We do this because generally
                    # the number of groups we're in is small and therefore this will be quick, whereas the groupsNear call is
                    # fairly slow.
                    $closestdist = PHP_INT_MAX;
                    $closestname = NULL;

                    # Get all the memberships.
                    if (!$membs) {
                        $sql = "SELECT memberships.userid, groups.id, groups.nameshort, groups.namefull, groups.lat, groups.lng FROM groups INNER JOIN memberships ON groups.id = memberships.groupid WHERE memberships.userid IN (" . implode(
                                ',',
                                $userids
                            ) . ") ORDER BY added ASC;";
                        $membs = $this->dbhr->preQuery($sql);
                    }

                    foreach ($membs as $memb) {
                        if ($memb['userid'] == $att['id']) {
                            $dist = \GreatCircle::getDistance($lat, $lng, $memb['lat'], $memb['lng']);

                            if ($dist < $closestdist) {
                                $closestdist = $dist;
                                $closestname = $memb['namefull'] ? $memb['namefull'] : $memb['nameshort'];
                            }
                        }
                    }

                    if ($closestname !== NULL) {
                        $grp = $closestname;

                        # The location name might be in the group name, in which case just use the group.
                        $loc = stripos($grp, $loc) !== FALSE ? NULL : $loc;
                    }
                }

                if ($loc) {
                    $display = $loc ? ($loc . ($grp ? ", $grp" : "")) : ($grp ? $grp : '');

                    $users[$att['id']]['info']['publiclocation'] = [
                        'display' => $display,
                        'location' => $loc,
                        'groupname' => $grp
                    ];

                    $userids = array_filter($userids, function($val) use ($att) {
                        return($val != $att['id']);
                    });
                }
            }

            if (count($userids) > 0) {
                # We have some left which don't have explicit postcodes.  Try for a group name.
                #
                # First check the group we used most recently.
                #error_log("Look for group name only for {$att['id']}");
                $found = [];
                foreach ($userids as $userid) {
                    $messages = $this->dbhr->preQuery("SELECT subject FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser = ? ORDER BY messages.arrival DESC LIMIT 1;", [
                        $userid
                    ]);

                    foreach ($messages as $msg) {
                        if (preg_match("/(.+)\:(.+)\((.+)\)/", $msg['subject'], $matches)) {
                            $grp = trim($matches[3]);

                            // Handle some misfromed locations which end up with spurious brackets.
                            $grp = preg_replace('/\(|\)/', '', $grp);

                            #error_log("Found $grp from post");

                            $users[$userid]['info']['publiclocation'] = [
                                'display' => $grp,
                                'location' => NULL,
                                'groupname' => $grp
                            ];
                            
                            $found[] = $userid;
                        }
                    }
                }
                
                $userids = array_diff($found, $userids);
                
                # Now check just membership.
                if (count($userids)) {
                    if (!$membs) {
                        $sql = "SELECT memberships.userid, groups.id, groups.nameshort, groups.namefull, groups.lat, groups.lng FROM groups INNER JOIN memberships ON groups.id = memberships.groupid WHERE memberships.userid IN (" . implode(
                                ',',
                                $userids
                            ) . ") ORDER BY added ASC;";
                        $membs = $this->dbhr->preQuery($sql);
                    }
                    
                    foreach ($userids as $userid) {
                        # Now check the group we joined most recently.
                        foreach ($membs as $memb) {
                            if ($memb['userid'] == $userid) {
                                $grp = $memb['namefull'] ? $memb['namefull'] : $memb['nameshort'];
                                #error_log("Found $grp from membership");

                                $users[$userid]['info']['publiclocation'] = [
                                    'display' => $grp,
                                    'location' => NULL,
                                    'groupname' => $grp
                                ];
                            }
                        }
                    }
                }
            }

            // TODO Remove after 2021-03-01
            foreach ($users as $user) {
                $userid = $user['id'];
                $users[$userid]['publiclocation'] = Utils::presdef('publiclocation', $users[$userid]['info'], NULL);
            }
        }
    }

    public function getLatLngs($users, $usedef = TRUE, $usegroup = TRUE, $needgroup = FALSE, $atts = NULL, $blur = NULL)
    {
        $userids = array_filter(array_column($users, 'id'));
        $ret = [];

        if ($userids && count($userids)) {
            $atts = $atts ? $atts : $this->dbhr->preQuery("SELECT id, settings, lastlocation FROM users WHERE id in (" . implode(',', $userids) . ");", NULL, FALSE, FALSE);

            foreach ($atts as $att) {
                $lat = NULL;
                $lng = NULL;
                $loc = NULL;

                if (Utils::pres('settings', $att)) {
                    $settings = $att['settings'];
                    $settings = json_decode($settings, TRUE);

                    if (Utils::pres('mylocation', $settings)) {
                        $lat = $settings['mylocation']['lat'];
                        $lng = $settings['mylocation']['lng'];
                        $loc = Utils::presdef('name', $settings['mylocation'], NULL);
                        #error_log("Got from mylocation $lat, $lng, $loc");
                    }
                }

                if ($lat === NULL) {
                    $lid = $this->getPrivate('lastlocation');

                    if ($lid) {
                        $l = new Location($this->dbhr, $this->dbhm, $lid);
                        $lat = $l->getPrivate('lat');
                        $lng = $l->getPrivate('lng');
                        $loc = $l->getPrivate('name');
                        #error_log("Got from last location $lat, $lng, $loc");
                    }
                }

                if ($lat !== NULL) {
                    $ret[$att['id']] = [
                        'lat' => $lat,
                        'lng' => $lng,
                        'loc' => $loc,
                    ];

                    $userids = array_filter($userids, function($id) use ($att) {
                        return $id !== $att['id'];
                    });
                }
            }
        }

        if ($userids && count($userids) && $usegroup) {
            # Still some we haven't handled.  Get the last message posted on a group with a location, if any.
            $membs = $this->dbhr->preQuery("SELECT fromuser AS userid, lat, lng FROM messages WHERE fromuser IN (" . implode(',', $userids) . ") AND lat IS NOT NULL AND lng IS NOT NULL ORDER BY arrival ASC;", NULL, FALSE, FALSE);
            foreach ($membs as $memb) {
                $ret[$memb['userid']] = [
                    'lat' => $memb['lat'],
                    'lng' => $memb['lng']
                ];

                #error_log("Got from last message posted {$memb['lat']}, {$memb['lng']}");

                $userids = array_filter($userids, function($id) use ($memb) {
                    return $id !== $memb['userid'];
                });
            }
        }

        if ($userids && count($userids) && $usegroup) {
            # Still some we haven't handled.  Get the memberships.  Logic will choose most recently joined.
            $membs = $this->dbhr->preQuery("SELECT userid, lat, lng, nameshort, namefull FROM groups INNER JOIN memberships ON memberships.groupid = groups.id WHERE userid IN (" . implode(',', $userids) . ") ORDER BY added ASC;", NULL, FALSE, FALSE);
            foreach ($membs as $memb) {
                $ret[$memb['userid']] = [
                    'lat' => $memb['lat'],
                    'lng' => $memb['lng'],
                    'group' => Utils::presdef('namefull', $memb, $memb['nameshort'])
                ];

                #error_log("Got from membership {$memb['lat']}, {$memb['lng']}, " . Utils::presdef('namefull', $memb, $memb['nameshort']));

                $userids = array_filter($userids, function($id) use ($memb) {
                    return $id !== $memb['userid'];
                });
            }
        }

        if ($userids && count($userids)) {
            # Still some we haven't handled.
            foreach ($userids as $userid) {
                if ($usedef) {
                    $ret[$userid] = [
                        'lat' => 53.9450,
                        'lng' => -2.5209
                    ];
                } else {
                    $ret[$userid] = NULL;
                }
            }
        }

        if ($needgroup) {
            # Get a group name.
            $membs = $this->dbhr->preQuery("SELECT userid, nameshort, namefull FROM groups INNER JOIN memberships ON memberships.groupid = groups.id WHERE userid IN (" . implode(',', array_filter(array_column($users, 'id'))) . ") ORDER BY added ASC;", NULL, FALSE, FALSE);
            foreach ($membs as $memb) {
                $ret[$memb['userid']] = [
                    'group' => Utils::presdef('namefull', $memb, $memb['nameshort'])
                ];
            }
        }

        if ($blur) {
            foreach ($ret as &$memb) {
                if ($memb['lat'] || $memb['lng']) {
                    # 3 decimal places is roughly 100m.
                    $memb['lat'] = round($memb['lat'], 3);
                    $memb['lng'] = round($memb['lng'], 3);
                }
            }
        }

        return ($ret);
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

    public function updateKudos($id = NULL, $force = FALSE)
    {
        $current = $this->getKudos($id);

        # Only update if we don't have one or it's older than a day.  This avoids repeatedly updating the entry
        # for the same user in some bulk operations.
        if (!Utils::pres('timestamp', $current) || (time() - strtotime($current['timestamp']) > 24 * 60 * 60)) {
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
            $platform = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM messages WHERE fromuser = ? AND arrival >= '$start' AND sourceheader = ?;", [
                    $id,
                    Message::PLATFORM
                ])[0]['count'] > 0;

            $kudos = $posts + $chats + $newsfeed + $events + $vols;

            if ($kudos > 0 || $force) {
                # No sense in creating entries which are blank or the same.
                $current = $this->getKudos($id);

                if ($current['kudos'] != $kudos || $force) {
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
        $limit = intval($limit);

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
        $limit = intval($limit);
        $start = date('Y-m-d', strtotime("60 days ago"));
        $sql = "SELECT users_kudos.* FROM users_kudos INNER JOIN users ON users.id = users_kudos.userid INNER JOIN memberships ON memberships.userid = users_kudos.userid AND memberships.groupid = ? INNER JOIN groups ON groups.id = memberships.groupid INNER JOIN locations_spatial ON users.lastlocation = locations_spatial.locationid WHERE memberships.role = ? AND users_kudos.platform = 1 AND users_kudos.facebook = 1 AND ST_Contains(GeomFromText(groups.poly), locations_spatial.geometry) AND bouncing = 0 AND lastaccess >= '$start' ORDER BY kudos DESC LIMIT $limit;";
        $kudos = $this->dbhr->preQuery($sql, [
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
        $tag = Utils::randstr(64);

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
        $d['Your_Yahoo_ID'] = $this->getPrivate('yahooid');
        $d['Your_role_on_the_system'] = $this->getPrivate('systemrole');
        $d['When_you_joined_the_site'] = Utils::ISODate($this->getPrivate('added'));
        $d['When_you_last_accessed_the_site'] = Utils::ISODate($this->getPrivate('lastaccess'));
        $d['When_we_last_checked_for_relevant_posts_for_you'] = Utils::ISODate($this->getPrivate('lastrelevantcheck'));
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

            $location = Utils::presdef('id', Utils::presdef('mylocation', $settings, []), NULL);

            if ($location) {
                $l = new Location($this->dbhr, $this->dbhm, $location);
                $d['Last_location_you_entered'] = $l->getPrivate('name') . ' (' . $l->getPrivate('lat') . ', ' . $l->getPrivate('lng') . ')';
            }

            $notifications = Utils::pres('notifications', $settings);

            $d['Notifications']['Send_email_notifications_for_chat_messages'] = Utils::presdef('email', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_email_notifications_of_chat_messages_you_send'] = Utils::presdef('emailmine', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_notifications_for_apps'] = Utils::presdef('app', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_push_notifications_to_web_browsers'] = Utils::presdef('push', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_Facebook_notifications'] = Utils::presdef('facebook', $notifications, TRUE) ? 'Yes' : 'No';
            $d['Notifications']['Send_emails_about_notifications_on_the_site'] = Utils::presdef('notificationmails', $notifications, TRUE) ? 'Yes' : 'No';

            $d['Hide_profile_picture'] = Utils::presdef('useprofile', $settings, TRUE) ? 'Yes' : 'No';

            if ($this->isModerator()) {
                $d['Show_members_that_you_are_a_moderator'] = Utils::pres('showmod', $settings) ? 'Yes' : 'No';

                switch (Utils::presdef('modnotifs', $settings, 4)) {
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

                switch (Utils::presdef('backupmodnotifs', $settings, 12)) {
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

                $d['Show_members_that_you_are_a_moderator'] = Utils::presdef('showmod', $settings, TRUE) ? 'Yes' : 'No';
            }
        }

        # Invitations.  Only show what we sent; the outcome is not this user's business.
        error_log("...invitations");
        $invites = $this->listInvitations("1970-01-01");
        $d['invitations'] = [];

        foreach ($invites as $invite) {
            $d['invitations'][] = [
                'email' => $invite['email'],
                'date' => Utils::ISODate($invite['date'])
            ];
        }

        error_log("...emails");
        $d['emails'] = $this->getEmails();

        foreach ($d['emails'] as &$email) {
            $email['added'] = Utils::ISODate($email['added']);

            if ($email['validated']) {
                $email['validated'] = Utils::ISODate($email['validated']);
            }
        }

        $phones = $this->dbhr->preQuery("SELECT * FROM users_phones WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($phones as $phone) {
            $d['phone'] = $phone['number'];
            $d['phonelastsent'] = Utils::ISODate($phone['lastsent']);
            $d['phonelastclicked'] = Utils::ISODate($phone['lastclicked']);
        }

        error_log("...logins");
        $d['logins'] = $this->dbhr->preQuery("SELECT type, uid, added, lastaccess FROM users_logins WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['logins'] as &$dd) {
            $dd['added'] = Utils::ISODate($dd['added']);
            $dd['lastaccess'] = Utils::ISODate($dd['lastaccess']);
        }

        error_log("...memberships");
        $d['memberships'] = $this->getMemberships();

        error_log("...memberships history");
        $sql = "SELECT DISTINCT memberships_history.*, groups.nameshort, groups.namefull FROM memberships_history INNER JOIN groups ON memberships_history.groupid = groups.id WHERE userid = ? ORDER BY added ASC;";
        $membs = $this->dbhr->preQuery($sql, [$this->id]);
        foreach ($membs as &$memb) {
            $name = $memb['namefull'] ? $memb['namefull'] : $memb['nameshort'];
            $memb['namedisplay'] = $name;
            $memb['added'] = Utils::ISODate($memb['added']);
        }

        $d['membershipshistory'] = $membs;

        error_log("...searches");
        $d['searches'] = $this->dbhr->preQuery("SELECT search_history.date, search_history.term, locations.name AS location FROM search_history LEFT JOIN locations ON search_history.locationid = locations.id WHERE search_history.userid = ? ORDER BY search_history.date ASC;", [
            $this->id
        ]);

        foreach ($d['searches'] as &$s) {
            $s['date'] = Utils::ISODate($s['date']);
        }

        error_log("...alerts");
        $d['alerts'] = $this->dbhr->preQuery("SELECT subject, responded, response FROM alerts_tracking INNER JOIN alerts ON alerts_tracking.alertid = alerts.id WHERE userid = ? AND responded IS NOT NULL ORDER BY responded ASC;", [
            $this->id
        ]);

        foreach ($d['alerts'] as &$s) {
            $s['responded'] = Utils::ISODate($s['responded']);
        }

        error_log("...donations");
        $d['donations'] = $this->dbhr->preQuery("SELECT * FROM users_donations WHERE userid = ? ORDER BY timestamp ASC;", [
            $this->id
        ]);

        foreach ($d['donations'] as &$s) {
            $s['timestamp'] = Utils::ISODate($s['timestamp']);
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
                'date' => Utils::ISODate($ban['date']),
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
            $s['added'] = Utils::ISODate($s['added']);
            $u = User::get($this->dbhr, $this->dbhm, $s['userid']);
            $s['email'] = $u->getEmailPreferred();
        }

        $d['spamdomains'] = $this->dbhr->preQuery("SELECT domain, date FROM spam_whitelist_links WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['spamdomains'] as &$s) {
            $s['date'] = Utils::ISODate($s['date']);
        }

        error_log("...images");
        $images = $this->dbhr->preQuery("SELECT id, url FROM users_images WHERE userid = ?;", [
            $this->id
        ]);

        $d['images'] = [];

        foreach ($images as $image) {
            if (Utils::pres('url', $image)) {
                $d['images'][] = [
                    'id' => $image['id'],
                    'thumb' => $image['url']
                ];
            } else {
                $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_USER);
                $d['images'][] = [
                    'id' => $image['id'],
                    'thumb' => $a->getPath(TRUE, $image['id'])
                ];
            }
        }

        error_log("...notifications");
        $d['notifications'] = $this->dbhr->preQuery("SELECT timestamp, url FROM users_notifications WHERE touser = ? AND seen = 1;", [
            $this->id
        ]);

        foreach ($d['notifications'] as &$n) {
            $n['timestamp'] = Utils::ISODate($n['timestamp']);
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
            $comm['date'] = Utils::ISODate($comm['date']);
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
                'date' => Utils::ISODate($loc['date'])
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
                $thisone['date'] = Utils::ISODate($rost['date']);
            }

            # Get the messages we have sent in this chat.
            $msgs = $this->dbhr->preQuery("SELECT id FROM chat_messages WHERE chatid = ? AND (userid = ? OR reviewedby = ?);", [
                $chatid['id'],
                $this->id,
                $this->id
            ]);

            $userlist = NULL;

            foreach ($msgs as $msg) {
                $cm = new ChatMessage($this->dbhr, $this->dbhm, $msg['id']);
                $thismsg = $cm->getPublic(FALSE, $userlist);

                # Strip out most of the refmsg detail - it's not ours and we need to save volume of data.
                $refmsg = Utils::pres('refmsg', $thismsg);

                if ($refmsg) {
                    $thismsg['refmsg'] = [
                        'id' => $msg['id'],
                        'subject' => Utils::presdef('subject', $refmsg, NULL)
                    ];
                }

                $thismsg['mine'] = Utils::presdef('userid', $thismsg, NULL) == $this->id;
                $thismsg['date'] = Utils::ISODate($thismsg['date']);
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
            $dd['timestamp'] = Utils::ISODate($dd['timestamp']);
        }

        $d['newsfeed_likes'] = $this->dbhr->preQuery("SELECT * FROM newsfeed_likes WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['newsfeed_likes'] as &$dd) {
            $dd['timestamp'] = Utils::ISODate($dd['timestamp']);
        }

        $d['newsfeed_reports'] = $this->dbhr->preQuery("SELECT * FROM newsfeed_reports WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['newsfeed_reports'] as &$dd) {
            $dd['timestamp'] = Utils::ISODate($dd['timestamp']);
        }

        $d['aboutme'] = $this->dbhr->preQuery("SELECT timestamp, text FROM users_aboutme WHERE userid = ? AND LENGTH(text) > 5;", [
            $this->id
        ]);

        foreach ($d['aboutme'] as &$dd) {
            $dd['timestamp'] = Utils::ISODate($dd['timestamp']);
        }

        error_log("...stories");
        $d['stories'] = $this->dbhr->preQuery("SELECT date, headline, story FROM users_stories WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['stories'] as &$dd) {
            $dd['date'] = Utils::ISODate($dd['date']);
        }

        $d['stories_likes'] = $this->dbhr->preQuery("SELECT storyid FROM users_stories_likes WHERE userid = ?;", [
            $this->id
        ]);

        error_log("...exports");
        $d['exports'] = $this->dbhr->preQuery("SELECT userid, started, completed FROM users_exports WHERE userid = ?;", [
            $this->id
        ]);

        foreach ($d['exports'] as &$dd) {
            $dd['started'] = Utils::ISODate($dd['started']);
            $dd['completed'] = Utils::ISODate($dd['completed']);
        }

        error_log("...logs");
        $l = new Log($this->dbhr, $this->dbhm);
        $ctx = NULL;
        $d['logs'] = $l->get(NULL, NULL, NULL, NULL, NULL, NULL, PHP_INT_MAX, $ctx, $this->id);

        error_log("...add group to logs");
        $loggroups = [];
        foreach ($d['logs'] as &$log) {
            if (Utils::pres('groupid', $log)) {
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

        # Gift aid
        $don = new Donations($this->dbhr, $this->dbhm);
        $d['giftaid'] = $don->getGiftAid($this->id);

        $ret = $d;

        # There are some other tables with information which we don't return.  Here's what and why:
        # - Not part of the current UI so can't have any user data
        #     polls_users
        # - Covered by data that we do return from other tables
        #     messages_drafts, messages_history, messages_groups, messages_outcomes,
        #     messages_promises, users_modmails, modnotifs, users_dashboard,
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
        Utils::filterResult($ret);
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
            $ret['requested'] = $ret['requested'] ? Utils::ISODate($ret['requested']) : NULL;
            $ret['started'] = $ret['started'] ? Utils::ISODate($ret['started']) : NULL;
            $ret['completed'] = $ret['completed'] ? Utils::ISODate($ret['completed']) : NULL;

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

    public function forget($reason)
    {
        # Wipe a user of personal data, for the GDPR right to be forgotten.  We don't delete the user entirely
        # otherwise it would mess up the stats.

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
            $this->dbhm->preExec("UPDATE messages SET fromip = NULL, message = NULL, envelopefrom = NULL, fromname = NULL, fromaddr = NULL, messageid = NULL, textbody = NULL, htmlbody = NULL, deleted = NOW() WHERE id = ?;", [
                $msg['id']
            ]);

            $this->dbhm->preExec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ?;", [
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

        # Remove any promises.
        $this->dbhm->preExec("DELETE FROM messages_promises WHERE userid = ?;", [
            $this->id
        ]);

        $this->dbhm->preExec("UPDATE users SET deleted = NOW() WHERE id = ?;", [
            $this->id
        ]);


        $l = new Log($this->dbhr, $this->dbhm);
        $l->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_DELETED,
            'user' => $this->id,
            'text' => $reason
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
        ]);

        foreach ($users as $user) {
            $logs = $this->dbhr->preQuery("SELECT DATEDIFF(NOW(), timestamp) AS logsago FROM logs WHERE user = ? AND (type != ? OR subtype != ?) ORDER BY id DESC LIMIT 1;", [
                $user['id'],
                Log::TYPE_USER,
                Log::SUBTYPE_CREATED
            ]);

            error_log("#{$user['id']} Found logs " . count($logs) . " age " . (count($logs) > 0 ? $logs['0']['logsago'] : ' none '));

            if (count($logs) == 0 || $logs[0]['logsago'] > 90) {
                error_log("...forget user #{$user['id']} " . (count($logs) > 0 ? $logs[0]['logsago'] : ''));
                $u = new User($this->dbhr, $this->dbhm, $user['id']);
                $u->forget('Inactive');
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
        ]);

        if (count($already) == 0) {
            $this->dbhm->background("INSERT IGNORE INTO users_active (userid, timestamp) VALUES ({$this->id}, '$now');");
        }
    }

    public function getActive()
    {
        $active = $this->dbhr->preQuery("SELECT * FROM users_active WHERE userid = ?;", [$this->id]);
        return ($active);
    }

    public function mostActive($gid, $limit = 20)
    {
        $limit = intval($limit);
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

            if (Utils::pres('memberof', $thisone)) {
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
        $num = preg_replace('/^(\+)?[04]+([^4])/', '$2', $num);

        if (substr($num, 0, 1) === '0') {
            $num = substr($num, 1);
        }

        $num = "+44$num";

        return ($num);
    }

    public function sms($msg, $url, $from = TWILIO_FROM, $sid = TWILIO_SID, $auth = TWILIO_AUTH, $forcemsg = NULL)
    {
        # We only want to send SMS to people who are clicking on the links.  So if we've sent them one and they've
        # not clicked on it, we stop.  This saves significant amounts of money.
        $phones = $this->dbhr->preQuery("SELECT * FROM users_phones WHERE userid = ? AND valid = 1 AND (lastsent IS NULL OR (lastclicked IS NOT NULL AND lastclicked > lastsent));", [
            $this->id
        ]);

        foreach ($phones as $phone) {
            try {
                $last = Utils::presdef('lastsent', $phone, NULL);
                $last = $last ? strtotime($last) : NULL;

                # Only send one SMS per day.  This keeps the cost down.
                if ($forcemsg || !$last || (time() - $last > 24 * 60 * 60)) {
                    $client = new Client($sid, $auth);

                    $text = $forcemsg ? $forcemsg : "$msg Click $url Don't reply to this text.  No more texts sent today.";
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
                    error_log("Don't send SMS to {$phone['number']}, too recent");
                }
            } catch (\Exception $e) {
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
            $ret = [ $phone['number'], Utils::ISODate($phone['lastsent']), Utils::ISODate($phone['lastclicked']) ];
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
            if ($rating !== NULL) {
                $this->dbhm->preExec("REPLACE INTO ratings (rater, ratee, rating, timestamp) VALUES (?, ?, ?, NOW());", [
                    $rater,
                    $ratee,
                    $rating
                ]);

                $ret = $this->dbhm->lastInsertId();
            } else {
                $this->dbhm->preExec("DELETE FROM ratings WHERE rater = ? AND ratee = ?;", [
                    $rater,
                    $ratee
                ]);

                $ret = NULL;
            }
        }

        return($ret);
    }

    public function getRating() {
        return($this->getRatings([$this->id])[$this->id]);
    }

    public function getRatings($uids) {
        $mysqltime = date("Y-m-d", strtotime("Midnight 182 days ago"));
        $ret = [];
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        # We show visible ratings, or ones we have made ourselves.
        $sql = "SELECT ratee, COUNT(*) AS count, rating FROM ratings WHERE ratee IN (" . implode(',', $uids) . ") AND timestamp >= '$mysqltime' AND (rater = ? OR visible = 1) GROUP BY rating, ratee;";
        $ratings = $this->dbhr->preQuery($sql, [ $myid ]);

        foreach ($uids as $uid) {
            $ret[$uid] = [
                User::RATING_UP => 0,
                User::RATING_DOWN => 0,
                User::RATING_MINE => NULL
            ];

            foreach ($ratings as $rate) {
                if ($rate['ratee'] == $uid) {
                    $ret[$uid][$rate['rating']] = $rate['count'];
                }
            }
        }

        $ratings = $this->dbhr->preQuery("SELECT rating, ratee FROM ratings WHERE ratee IN (" . implode(',', $uids) . ") AND rater = ? AND timestamp >= '$mysqltime';", [
            $myid
        ]);

        foreach ($uids as $uid) {
            if ($myid != $this->id) {
                # We can't rate ourselves, so don't bother checking.

                foreach ($ratings as $rating) {
                    if ($rating['ratee'] == $uid) {
                        $ret[$uid][User::RATING_MINE] = $rating['rating'];
                    }
                }
            }
        }

        return($ret);
    }

    public function getRated() {
        $rateds = $this->dbhr->preQuery("SELECT * FROM ratings WHERE rater = ?;", [
            $this->id
        ]);

        foreach ($rateds as &$rate) {
            $rate['timestamp'] = Utils::ISODate($rate['timestamp']);
        }

        return($rateds);
    }

    public function getActiveSince($since, $createdbefore) {
        $sincetime = date("Y-m-d H:i:s", strtotime($since));
        $beforetime = date("Y-m-d H:i:s", strtotime($createdbefore));
        $ids = $this->dbhr->preQuery("SELECT id FROM users WHERE lastaccess >= ? AND added <= ?;", [
            $sincetime,
            $beforetime
        ]);

        return(count($ids) ? array_filter(array_column($ids, 'id')) : []);
    }

    public static function encodeId($id) {
        $bin = base_convert($id, 10, 2);
        $bin = str_replace('0', '-', $bin);
        $bin = str_replace('1', '~', $bin);
        return($bin);
    }

    public static function decodeId($enc) {
        $enc = trim($enc);
        $enc = str_replace('-', '0', $enc);
        $enc = str_replace('~', '1', $enc);
        $id  = base_convert($enc, 2, 10);
        return($id);
    }

    public function getCity()
    {
        $city = NULL;

        # Find the closest town
        list ($lat, $lng, $loc) = $this->getLatLng();
        $sql = "SELECT id, name, ST_distance(position, Point(?, ?)) AS dist FROM towns WHERE position IS NOT NULL ORDER BY dist ASC LIMIT 1;";
        #error_log("Get $sql, $lng, $lat");
        $towns = $this->dbhr->preQuery($sql, [$lng, $lat]);

        foreach ($towns as $town) {
            $city = $town['name'];
        }

        return([ $city, $lat, $lng ]);
    }

    public function microVolunteering() {
        // Are we on a group where microvolunteering is enabled.
        $groups = $this->dbhr->preQuery("SELECT memberships.id FROM memberships INNER JOIN groups ON groups.id = memberships.groupid WHERE userid = ? AND microvolunteering = 1 LIMIT 1;", [
            $this->id
        ]);

        return count($groups);
    }

    public function getJobAds() {
        # We want to show a few job ads from nearby.
        $search = NULL;
        $ret = '<span class="jobads">';

        list ($search, $lat, $lng) = $this->getCity();

        if ($search) {
            # AdView's servers can't keep up with us, so we keep a more or less daily cache of jobs per location.
            $fn = "/tmp/adview." . base64_encode($search);

            if (!is_file($fn) || (time() - filemtime($fn) > 20 * 3600)) {
                # No cache or time to update.
                $url = "https://uk.whatjobs.com/api/v1/jobs.json?publisher=2053&channel=email&limit=50&radius=5&location=" . urlencode($search);
                $data = @file_get_contents($url);
                file_put_contents($fn, $data);
            } else {
                $data = @file_get_contents($fn);
            }

            if ($data) {
                $data = json_decode($data, TRUE);
                if ($data && $data['data'] && count($data['data'])) {
                    $a = new AdView($this->dbhr, $this->dbhm);
                    $jobs = $a->sortJobs($data['data'], $this->id);
                    $jobs = array_slice($jobs, 0, 4);

                    foreach ($jobs as $job) {
                        $loc = Utils::presdef('location', $job, '') . ' ' . Utils::presdef('postcode', $job, '');
                        $title = "{$job['title']}" . ($loc !== ' ' ? " ($loc)" : '');
                        # Direct link to job to increase click conversions.
                        $url = 'https://' . USER_SITE . '/jobs/' . urlencode($search);
                        #$url = $job['url'];
                        $ret .= '<a href="' . $url . '" target="_blank">' . htmlentities($title) . '</a><br />';
                    }
                }
            }
        }

        $ret .= '</span>';

        return([
            'location' => $search,
            'jobs' => $ret
        ]);
    }

    public function updateModMails($uid = NULL) {
        # We maintain a count of recent modmails by scanning logs regularly, and pruning old ones.  This means we can
        # find the value in a well-indexed way without the disk overhead of having a two-column index on logs.
        #
        # Ignore logs where the user is the same as the byuser - for example a user can delete their own posts, and we are
        # only interested in things where a mod has done something to another user.
        $mysqltime = date("Y-m-d H:i:s", strtotime("10 minutes ago"));
        $uidq = $uid ? " AND user = $uid " : '';

        $logs = $this->dbhr->preQuery("SELECT * FROM logs WHERE timestamp > ? AND ((type = 'Message' AND subtype IN ('Rejected', 'Deleted', 'Replied')) OR (type = 'User' AND subtype IN ('Mailed', 'Rejected', 'Deleted'))) AND byuser != user $uidq;", [
            $mysqltime
        ]);

        foreach ($logs as $log) {
            $this->dbhm->preExec("INSERT IGNORE INTO users_modmails (userid, logid, timestamp, groupid) VALUES (?,?,?,?);", [
                $log['user'],
                $log['id'],
                $log['timestamp'],
                $log['groupid']
            ]);
        }

        # Prune old ones.
        $mysqltime = date("Y-m-d", strtotime("Midnight 30 days ago"));
        $uidq2 = $uid ? " AND userid = $uid " : '';

        $logs = $this->dbhr->preQuery("SELECT id FROM users_modmails WHERE timestamp < ? $uidq2;", [
            $mysqltime
        ]);

        foreach ($logs as $log) {
            $this->dbhm->preExec("DELETE FROM users_modmails WHERE id = ?;", [ $log['id'] ], FALSE);
        }
    }

    public function getModGroupsByActivity() {
        $start = date('Y-m-d', strtotime("60 days ago"));
        $sql = "SELECT COUNT(*) AS count, CASE WHEN namefull IS NOT NULL THEN namefull ELSE nameshort END AS namedisplay FROM messages_groups INNER JOIN groups ON groups.id = messages_groups.groupid WHERE approvedby = ? AND arrival >= '$start' AND groups.publish = 1 AND groups.onmap = 1 AND groups.type = 'Freegle' GROUP BY groupid ORDER BY count DESC";
        return $this->dbhr->preQuery($sql, [
            $this->id
        ]);
    }

    public function related($userlist) {
        $userlist = array_unique($userlist);

        foreach ($userlist as $user1) {
            foreach ($userlist as $user2) {
                if ($user1 && $user2 && $user1 !== $user2) {
                    $this->dbhm->background("INSERT INTO users_related (user1, user2) VALUES ($user1, $user2) ON DUPLICATE KEY UPDATE timestamp = NOW();");
                }
            }
        }
    }

    public function getRelated($userid, $since = "30 days ago") {
        $starttime = date("Y-m-d H:i:s", strtotime($since));
        $users = $this->dbhr->preQuery("SELECT * FROM users_related WHERE user1 = ? AND timestamp >= '$starttime';", [
            $userid
        ]);

        return ($users);
    }

    public function listRelated($groupids, &$ctx, $limit = 10) {
        # The < condition ensures we don't duplicate during a single run.
        $limit = intval($limit);
        $ret = [];
        $backstop = 100;

        do {
            $ctx = $ctx ? $ctx : [ 'id'  => NULL ];

            if ($groupids && count($groupids)) {
                $ctxq = ($ctx && intval($ctx['id'])) ? (" WHERE id < " . intval($ctx['id'])) : '';
                $groupq = "(" . implode(',', $groupids) . ")";
                $sql = "SELECT DISTINCT id, user1, user2 FROM (
SELECT users_related.id, user1, user2, memberships.groupid FROM users_related 
INNER JOIN memberships ON users_related.user1 = memberships.userid 
INNER JOIN users u1 ON users_related.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User'
WHERE 
user1 < user2 AND
notified = 0 AND
memberships.groupid IN $groupq UNION
SELECT users_related.id, user1, user2, memberships.groupid FROM users_related 
INNER JOIN memberships ON users_related.user2 = memberships.userid 
INNER JOIN users u2 ON users_related.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User'
WHERE 
user1 < user2 AND
notified = 0 AND
memberships.groupid IN $groupq 
) t $ctxq ORDER BY id DESC LIMIT $limit;";
                $members = $this->dbhr->preQuery($sql);
            } else {
                $ctxq = ($ctx && intval($ctx['id'])) ? (" AND users_related.id < " . intval($ctx['id'])) : '';
                $sql = "SELECT DISTINCT users_related.id, user1, user2 FROM users_related INNER JOIN users u1 ON u1.id = users_related.user1 AND u1.deleted IS NULL AND u1.systemrole = 'User' INNER JOIN users u2 ON u2.id = users_related.user2 AND u2.deleted IS NULL AND u2.systemrole = 'User' WHERE notified = 0 AND user1 < user2 $ctxq ORDER BY id DESC LIMIT $limit;";
                $members = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
            }

            $uids1 = array_column($members, 'user1');
            $uids2 = array_column($members, 'user2');

            $related = [];
            foreach ($members as $member) {
                $related[$member['user1']] = $member['user2'];
                $ctx['id'] = $member['id'];
            }

            $users = $this->getPublicsById(array_merge($uids1, $uids2));

            foreach ($users as &$user1) {
                if (Utils::pres($user1['id'], $related)) {
                    $thisone = $user1;

                    foreach ($users as $user2) {
                        if ($user2['id'] == $related[$user1['id']]) {
                            $user2['userid'] = $user2['id'];
                            $thisone['relatedto'] = $user2;
                            break;
                        }
                    }

                    $logins = $this->getLogins(FALSE, $thisone['id'], TRUE);
                    $rellogins = $this->getLogins(FALSE, $thisone['relatedto']['id'], TRUE);

                    if ($thisone['deleted'] ||
                        $thisone['relatedto']['deleted'] ||
                        $thisone['systemrole'] != User::SYSTEMROLE_USER ||
                        $thisone['relatedto']['systemrole'] != User::SYSTEMROLE_USER) {
                        # No sense in telling people about these.
                        $this->dbhm->preExec("UPDATE users_related SET notified = 1 WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?);", [
                            $thisone['id'],
                            $thisone['relatedto']['id'],
                            $thisone['relatedto']['id'],
                            $thisone['id']
                        ]);
                    } elseif (!count($logins) || !count($rellogins)) {
                        # No valid login types for one of the users - no way they can log in again so no point notifying.
                        $this->dbhm->preExec("UPDATE users_related SET notified = 1 WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?);", [
                            $thisone['id'],
                            $thisone['relatedto']['id'],
                            $thisone['relatedto']['id'],
                            $thisone['id']
                        ]);
                    } else {
                        $thisone['userid'] = $thisone['id'];
                        $thisone['logins'] = $logins;
                        $thisone['relatedto']['logins'] = $rellogins;

                        $ret[] = $thisone;
                    }
                }
            }

            $backstop--;
        } while ($backstop > 0 && count($ret) < $limit && count($members));

        return $ret;
    }

    public function getExpectedReplies($uids, $since = ChatRoom::ACTIVELIM, $grace = 30) {
        # We count replies where the user has been active since the reply was requested, which means they've had
        # a chance to reply, plus a grace period in minutes, so that if they're active right now we don't penalise them.
        #
        # $since here has to match the value in ChatRoom::
        $starttime = date("Y-m-d H:i:s", strtotime($since));
        $replies = $this->dbhr->preQuery("SELECT COUNT(*) AS count, expectee FROM users_expected INNER JOIN users ON users.id = users_expected.expectee INNER JOIN chat_messages ON chat_messages.id = users_expected.chatmsgid WHERE expectee IN (" . implode(',', $uids) . ") AND chat_messages.date >= '$starttime' AND replyreceived = 0 AND TIMESTAMPDIFF(MINUTE, chat_messages.date, users.lastaccess) > ?", [
            $grace
        ]);

        return($replies);
    }

    public function listExpectedReplies($uid, $since = ChatRoom::ACTIVELIM, $grace = 30) {
        # We count replies where the user has been active since the reply was requested, which means they've had
        # a chance to reply, plus a grace period in minutes, so that if they're active right now we don't penalise them.
        #
        # $since here has to match the value in ChatRoom::
        $starttime = date("Y-m-d H:i:s", strtotime($since));
        $replies = $this->dbhr->preQuery("SELECT chatid FROM users_expected INNER JOIN users ON users.id = users_expected.expectee INNER JOIN chat_messages ON chat_messages.id = users_expected.chatmsgid WHERE expectee = ? AND chat_messages.date >= '$starttime' AND replyreceived = 0 AND TIMESTAMPDIFF(MINUTE, chat_messages.date, users.lastaccess) > ?", [
            $uid,
            $grace
        ]);

        $ret = [];

        if (count($replies)) {
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = $me ? $me->getId() : NULL;

            $r = new ChatRoom($this->dbhr, $this->dbhm);
            $rooms = $r->fetchRooms(array_column($replies, 'chatid'), $myid, TRUE);

            foreach ($rooms as $room) {
                $ret[] = [
                    'id' => $room['id'],
                    'name' => $room['name']
                ];
            }
        }

        return $ret;
    }
    
    public function getWorkCounts($groups = NULL) {
        # Tell them what mod work there is.  Similar code in Notifications.
        $ret = [];
        $total = 0;

        $national = $this->hasPermission(User::PERM_NATIONAL_VOLUNTEERS);

        if ($national) {
            $v = new Volunteering($this->dbhr, $this->dbhm);
            $ret['pendingvolunteering'] = $v->systemWideCount();
        }

        $s = new Spam($this->dbhr, $this->dbhm);
        $spamcounts = $s->collectionCounts();
        $ret['spammerpendingadd'] = $spamcounts[Spam::TYPE_PENDING_ADD];
        $ret['spammerpendingremove'] = $spamcounts[Spam::TYPE_PENDING_REMOVE];

        # Show social actions from last 4 days.
        $ctx = NULL;
        $f = new GroupFacebook($this->dbhr, $this->dbhm);
        $ret['socialactions'] = count($f->listSocialActions($ctx));

        $s = new Story($this->dbhr, $this->dbhm);
        $ret['stories'] = $s->getReviewCount(FALSE, $this);
        $ret['newsletterstories'] = $this->hasPermission(User::PERM_NEWSLETTER) ? $s->getReviewCount(TRUE) : 0;

        if ($this->hasPermission(User::PERM_GIFTAID)) {
            $d = new Donations($this->dbhr, $this->dbhm);
            $ret['giftaid'] = $d->countGiftAidReview();
        }

        if (!$groups) {
            # When the user posts, MODTOOLS will be FALSE but we need to notify mods.
            $groups = $this->getMemberships(FALSE, NULL, TRUE, TRUE, $this->id);
        }

        foreach ($groups as &$group) {
            if (Utils::pres('work', $group)) {
                foreach ($group['work'] as $key => $work) {
                    if (Utils::pres($key, $ret)) {
                        $ret[$key] += $work;
                    } else {
                        $ret[$key] = $work;
                    }
                }
            }
        }

        // All the types of work which are worth nagging about.
        $worktypes = [
            'pendingvolunteering',
            'socialactions',
            'chatreview',
            'relatedmembers',
            'stories',
            'newsletterstories',
            'pending',
            'spam',
            'pendingmembers',
            'pendingevents',
            'spammembers',
            'editreview',
            'pendingadmins'
        ];

        if ($this->isAdminOrSupport()) {
            $worktypes[] = 'spammerpendingadd';
            $worktypes[] = 'spammerpendingremove';
        }

        foreach ($worktypes as $key) {
            $total += Utils::presdef($key, $ret, 0);
        }

        $ret['total'] = $total;

        return $ret;
    }

    public function ratingVisibility($since = "1 hour ago") {
        $mysqltime = date("Y-m-d", strtotime($since));

        $ratings = $this->dbhr->preQuery("SELECT * FROM ratings WHERE timestamp >= ?;", [
            $mysqltime
        ]);

        foreach ($ratings as $rating) {
            # A rating is visible to others if there is a chat between the two members, and
            # - the ratee replied to a post, or
            # - there is at least one message from each of them.
            # This means that has been an exchange substantial enough for the rating not to just be frivolous.  It
            # deliberately excludes interactions on ChitChat, where we have seen some people go a bit overboard on
            # rating people.
            $visible = FALSE;

            $chats = $this->dbhr->preQuery("SELECT id FROM chat_rooms WHERE (user1 = ? AND user2 = ?) OR (user2 = ? AND user1 = ?)", [
                $rating['rater'],
                $rating['ratee'],
                $rating['rater'],
                $rating['ratee'],
            ]);

            foreach ($chats as $chat) {
                $distincts = $this->dbhr->preQuery("SELECT COUNT(DISTINCT(userid)) AS count FROM chat_messages WHERE chatid = ?;", [
                    $chat['id']
                ]);

                if ($distincts[0]['count'] >= 2) {
                    $visible = TRUE;
                } else {
                    $replies = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE chatid = ? AND userid = ? AND refmsgid IS NOT NULL;", [
                        $chat['id'],
                        $rating['ratee']
                    ]);

                    if ($replies[0]['count']) {
                        $visible = TRUE;
                    }
                }
            }

            $oldvisible = intval($rating['visible']) ? TRUE : FALSE;

            if ($visible != $oldvisible) {
                $this->dbhm->preExec("UPDATE ratings SET visible = ? WHERE id = ?;", [
                    $visible,
                    $rating['id']
                ]);
            }
        }
    }

    public function unban($groupid) {
        $this->dbhm->preExec("DELETE FROM users_banned WHERE userid = ? AND groupid = ?;", [
            $this->id,
            $groupid
        ]);
    }

    public function hasFacebookLogin() {
        $logins = $this->getLogins();
        $ret = FALSE;

        foreach ($logins as $login) {
            if ($login['type'] == User::LOGIN_FACEBOOK) {
                $ret = TRUE;
            }
        }

        return $ret;
    }

    public function hasCovidConfirmed() {
        $covid = $this->getPrivate('covidconfirmed');

        # We want most of the UT to work without doing this.
        $ret = (getenv('UT') && !getenv('UTTESTCOVIDCONFIRM')) || $covid;

        return $ret;
    }

    public function covidConfirm($msgid) {
        # Mail the user and ask them to complete the COVID checklist, which they haven't done yet.
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/');
        $twig = new \Twig_Environment($loader);
        $email = $this->getEmailPreferred();

        $tn = strpos($email, 'user.trashnothing.com') !== FALSE ? '&tn=1' : '';
        $url = $this->loginLink(USER_SITE, $this->id, "/covidchecklist?msgid=$msgid$tn", NULL, TRUE);

        $html = $twig->render('covid.html', [
            'email' => $email,
            'url' => $url
        ]);

        list ($transport, $mailer) = Mail::getMailer();
        $message = \Swift_Message::newInstance()
            ->setSubject("COVID-19 - Action required to process your message")
            ->setFrom([NOREPLY_ADDR => SITE_NAME])
            ->setTo($email)
            ->setDate(time())
            ->setBody("Action required - complete our COVID Checklist at $url");

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        Mail::addHeaders($message, Mail::COVID_CHECKLIST, $this->getId());

        $this->sendIt($mailer, $message);
    }

    public function memberReview($groupid, $request, $reason) {
        $mysqltime = date('Y-m-d H:i');

        if ($request) {
            # Requesting review.
            $this->setMembershipAtt($groupid, 'reviewreason', $reason);
            $this->setMembershipAtt($groupid, 'reviewrequestedat', $mysqltime);
            $this->setMembershipAtt($groupid, 'reviewedat', NULL);
        } else {
            # We have reviewed.  Note that they might have been removed, in which case the set will do nothing.
            $this->setMembershipAtt($groupid, 'reviewrequestedat', NULL);
            $this->setMembershipAtt($groupid, 'reviewedat', $mysqltime);
        }
    }

    private function checkSupporterSettings($settings) {
        $ret = TRUE;

        if ($settings) {
            $s = json_decode($settings, TRUE);

            if ($s && array_key_exists('hidesupporter', $s)) {
                $ret = !$s['hidesupporter'];
            }
        }

        return $ret;
    }

    public function getSupporters(&$rets) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        # A supporter is someone who has donated recently, or done microvolunteering recently.
        $userids = array_filter(array_keys($rets));

        if (count($userids)) {
            $start = date('Y-m-d', strtotime("60 days ago"));
            $info = $this->dbhr->preQuery("SELECT DISTINCT userid, settings FROM microactions INNER JOIN users ON users.id = microactions.userid WHERE microactions.timestamp >= ? AND microactions.userid IN (" . implode(',', $userids) .");", [
                $start
            ]);

            $found = [];

            foreach ($info as $i) {
                $rets[$i['userid']]['supporter'] = $this->checkSupporterSettings($i['settings']);
                $found[] = $i['userid'];
            }

            $left = array_diff($userids, $found);

            # If we are one of the users, then we want to return whether we are a donor.
            if (in_array($myid, $userids)) {
                $left[] = $myid;
                $left = array_filter(array_unique($left));
            }

            if (count($left)) {
                $info = $this->dbhr->preQuery("SELECT userid, settings FROM users_donations INNER JOIN users ON users_donations.userid = users.id WHERE users_donations.timestamp >= ? AND users_donations.userid IN (" . implode(',', $left) . ");", [
                    $start
                ]);

                foreach ($info as $i) {
                    $rets[$i['userid']]['supporter'] = $this->checkSupporterSettings($i['settings']);

                    if ($i['userid'] == $myid) {
                        # Only return this info for ourselves, otherwise it's a privacy leak.
                        $rets[$i['userid']]['donor'] = TRUE;
                    }
                }
            }
        }
    }
}
