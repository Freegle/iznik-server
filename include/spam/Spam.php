<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/lib/GreatCircle.php');

use GeoIp2\Database\Reader;
use LanguageDetection\Language;

class Spam {
    const TYPE_SPAMMER = 'Spammer';
    const TYPE_WHITELIST = 'Whitelisted';
    const TYPE_PENDING_ADD = 'PendingAdd';
    const TYPE_PENDING_REMOVE = 'PendingRemove';
    const SPAM = 'Spam';
    const HAM = 'Ham';
    const URL_REMOVED = '(URL removed)';

    const USER_THRESHOLD = 5;
    const GROUP_THRESHOLD = 20;
    const SUBJECT_THRESHOLD = 30;  // SUBJECT_THRESHOLD must be > GROUP_THRESHOLD for UT
    const IMAGE_THRESHOLD = 5;
    const IMAGE_THRESHOLD_TIME = 24;

    # For checking users as suspect.
    const SEEN_THRESHOLD = 16; // Number of groups to join or apply to before considered suspect
    const ESCALATE_THRESHOLD = 2; // Level of suspicion before a user is escalated to support/admin for review
    const DISTANCE_THRESHOLD = 100; // Replies to items further apart than this is suspicious.  In miles.

    const REASON_NOT_SPAM = 'NotSpam';
    const REASON_COUNTRY_BLOCKED = 'CountryBlocked';
    const REASON_IP_USED_FOR_DIFFERENT_USERS = 'IPUsedForDifferentUsers';
    const REASON_IP_USED_FOR_DIFFERENT_GROUPS = 'IPUsedForDifferentGroups';
    const REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS = 'SubjectUsedForDifferentGroups';
    const REASON_SPAMASSASSIN = 'SpamAssassin';
    const REASON_GREETING = 'Greetings spam';
    const REASON_REFERRED_TO_SPAMMER = 'Referenced known spammer';
    const REASON_KNOWN_KEYWORD = 'Known spam keyword';
    const REASON_DBL = 'URL on DBL';
    const REASON_BULK_VOLUNTEER_MAIL = 'BulkVolunteerMail';
    const REASON_USED_OUR_DOMAIN = 'UsedOurDomain';
    const REASON_WORRY_WORD = 'WorryWord';
    const REASON_SCRIPT = 'Script';
    const REASON_LINK = 'Link';
    const REASON_MONEY = 'Money';
    const REASON_EMAIL = 'Email';
    const REASON_LANGUAGE = 'Language';
    const REASON_IMAGE_SENT_MANY_TIMES = 'SameImage';

    const ACTION_SPAM = 'Spam';
    const ACTION_REVIEW = 'Review';

    # A common type of spam involves two lines with greetings.
    private $greetings = [
        'hello', 'salutations', 'hey', 'good morning', 'sup', 'hi', 'good evening', 'good afternoon', 'greetings'
    ];

    /** @var  $dbhr LoggedPDO */
    private $dbhr;

    /** @var  $dbhm LoggedPDO */
    private $dbhm;
    private $reader = NULL;

    private $spamwords = NULL;

    function __construct($dbhr, $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        try {
            // This may fail in some test environments.
            $this->reader = new Reader(MMDB);
        } catch (\Exception $e) {}

        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public function checkMessage(Message $msg) {
        $ip = $msg->getFromIP();
        $host = NULL;

        // TN sends the IP in hashed form, so we can still do checks on use of the IP, but not look it up.
        $fromTN = $msg->getEnvelopefrom() == 'noreply@trashnothing.com' || strpos($msg->getEnvelopefrom(), '@user.trashnothing.com') !== FALSE;

        if (stripos($msg->getFromname(), GROUP_DOMAIN) !== FALSE || stripos($msg->getFromname(), USER_DOMAIN) !== FALSE) {
            # A domain which embeds one of ours in an attempt to fool us into thinking it is legit.
            return [ TRUE, Spam::REASON_USED_OUR_DOMAIN, "Used our domain inside from name " . $msg->getFromname() ] ;
        }

        if ($ip && !$fromTN) {
            if (strpos($ip, "10.") === 0) {
                # We've picked up an internal IP, ignore it.
                $ip = NULL;
            } else {
                $host = $msg->getFromhost();
                if (preg_match('/mail.*yahoo\.com/', $host)) {
                    # Posts submitted by email to Yahoo show up with an X-Originating-IP of one of Yahoo's MTAs.  We don't
                    # want to consider those as spammers.
                    $ip = NULL;
                    $msg->setFromIP($ip);
                } else {
                    # Check if it's whitelisted
                    $sql = "SELECT * FROM spam_whitelist_ips WHERE ip = ?;";
                    $ips = $this->dbhr->preQuery($sql, [$ip]);
                    foreach ($ips as $wip) {
                        $ip = NULL;
                        $msg->setFromIP($ip);
                    }
                }
            }
        }

        if ($ip && $this->reader) {
            if (!$fromTN) {
                # We have an IP, we reckon.  It's unlikely that someone would fake an IP which gave a spammer match, so
                # we don't have to worry too much about false positives.
                try {
                    $record = $this->reader->country($ip);
                    $country = $record->country->name;
                    $msg->setPrivate('fromcountry', $record->country->isoCode);
                } catch (\Exception $e) {
                    # Failed to look it up.
                    error_log("Failed to look up $ip " . $e->getMessage());
                    $country = NULL;
                }

                # Now see if we're blocking all mails from that country.  This is legitimate if our service is for a
                # single country and we are vanishingly unlikely to get legitimate emails from certain others.
                $countries = $this->dbhr->preQuery("SELECT * FROM spam_countries WHERE country = ?;", [$country]);
                foreach ($countries as $country) {
                    # Gotcha.
                    return(array(true, Spam::REASON_COUNTRY_BLOCKED, "Blocking IP $ip as it's in {$country['country']}"));
                }
            }

            # Now see if this IP has been used for too many different users.  That is likely to
            # be someone masquerading to fool people.
            $sql = "SELECT fromname FROM messages_history WHERE fromip = ? AND groupid IS NOT NULL GROUP BY fromuser ORDER BY arrival DESC;";
            $users = $this->dbhr->preQuery($sql, [$ip]);
            $numusers = count($users);

            if ($numusers > Spam::USER_THRESHOLD) {
                $list = [];
                foreach ($users as $user) {
                    $list[] = $user['fromname'];
                }
                return(array(true, Spam::REASON_IP_USED_FOR_DIFFERENT_USERS, "IP $ip " . ($host ? "($host)" : "") . " recently used for $numusers different users (" . implode(', ', $list) . ")"));
            }

            # Now see if this IP has been used for too many different groups.  That's likely to
            # be someone spamming.
            $sql = "SELECT groups.nameshort FROM messages_history INNER JOIN `groups` ON groups.id = messages_history.groupid WHERE fromip = ? GROUP BY groupid;";
            $groups = $this->dbhr->preQuery($sql, [$ip]);
            $numgroups = count($groups);

            if ($numgroups >= Spam::GROUP_THRESHOLD) {
                $list = [];
                foreach ($groups as $group) {
                    $list[] = $group['nameshort'];
                }
                return(array(true, Spam::REASON_IP_USED_FOR_DIFFERENT_GROUPS, "IP $ip ($host) recently used for $numgroups different groups (" . implode(', ', $list) . ")"));
            }
        }

        # Now check whether this subject (pace any location) is appearing on many groups.
        #
        # Don't check very short subjects - might be something like "TAKEN".
        $subj = $msg->getPrunedSubject();

        if (strlen($subj) >= 10) {
            $sql = "SELECT COUNT(DISTINCT groupid) AS count FROM messages_history WHERE prunedsubject LIKE ? AND groupid IS NOT NULL;";
            $counts = $this->dbhr->preQuery($sql, [
                "$subj%"
            ]);

            foreach ($counts as $count) {
                if ($count['count'] >= Spam::SUBJECT_THRESHOLD) {
                    # Possible spam subject - but check against our whitelist.
                    $found = FALSE;
                    $sql = "SELECT id FROM spam_whitelist_subjects WHERE subject = ?;";
                    $whites = $this->dbhr->preQuery($sql, [$subj]);
                    foreach ($whites as $white) {
                        $found = TRUE;
                    }

                    if (!$found) {
                        return (array(true, Spam::REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS, "Warning - subject $subj recently used on {$count['count']} groups"));
                    }
                }
            }
        }

        # Now check if this sender has mailed a lot of owners recently.
        $sql = "SELECT COUNT(*) AS count FROM messages WHERE envelopefrom = ? and envelopeto LIKE '%-volunteers@" . GROUP_DOMAIN . "' AND arrival >= '" . date("Y-m-d H:i:s", strtotime("24 hours ago")) . "'";
        $counts = $this->dbhr->preQuery($sql, [
            $msg->getEnvelopefrom()
        ]);

        foreach ($counts as $count) {
            if ($count['count'] >= Spam::GROUP_THRESHOLD) {
                return (array(true, Spam::REASON_BULK_VOLUNTEER_MAIL, "Warning - " . $msg->getEnvelopefrom() . " mailed {$count['count']} group volunteer addresses recently"));
            }
        }

        # Now check if this subject line has been used in mails to lots of owners recently.
        $sql = "SELECT COUNT(*) AS count FROM messages WHERE subject LIKE ? and envelopeto LIKE '%-volunteers@" . GROUP_DOMAIN . "' AND arrival >= '" . date("Y-m-d H:i:s", strtotime("24 hours ago")) . "'";
        $counts = $this->dbhr->preQuery($sql, [
            $msg->getSubject()
        ]);

        foreach ($counts as $count) {
            if ($count['count'] >= Spam::GROUP_THRESHOLD) {
//                mail("log@ehibbert.org.uk", "Spam subject " . $msg->getSubject(), "Warning - subject " . $msg->getSubject() . " mailed to {$count['count']} group volunteer addresses recently", [], '-fnoreply@modtools.org');
                return (array(true, Spam::REASON_BULK_VOLUNTEER_MAIL, "Warning - subject " . $msg->getSubject() . " mailed to {$count['count']} group volunteer addresses recently"));
            }
        }

        # Get the text to scan.  No point in scanning any text we would strip before passing it on.
        $text = $msg->stripQuoted();

        # Check if this is a greetings spam.
        if (stripos($text, 'http') || stripos($text, '.php')) {
            $p = strpos($text, "\n");
            $q = strpos($text, "\n", $p + 1);
            $r = strpos($text, "\n", $q + 1);

            $line1 = $p ? substr($text, 0, $p) : '';
            $line3 = $q ? substr($text, $q + 1, $r) : '';

            $line1greeting = FALSE;
            $line3greeting = FALSE;
            $subjgreeting = FALSE;

            foreach ($this->greetings as $greeting) {
                if (stripos($subj, $greeting) === 0) {
                    $subjgreeting = TRUE;
                }

                if (stripos($line1, $greeting) === 0) {
                    $line1greeting = TRUE;
                }

                if (stripos($line3, $greeting) === 0) {
                    $line3greeting = TRUE;
                }
            }

            if ($subjgreeting && $line1greeting || $line1greeting && $line3greeting) {
                return (array(true, Spam::REASON_GREETING, "Message looks like a greetings spam"));
            }
        }

        $spammail = $this->checkReferToSpammer($text);

        if ($spammail) {
            return (array(true, Spam::REASON_REFERRED_TO_SPAMMER, "Refers to known spammer $spammail"));
        }

        # Don't block spam from ourselves.
        if ($msg->getEnvelopefrom() != SUPPORT_ADDR && $msg->getEnvelopefrom() != INFO_ADDR) {
            # For messages we want to spot any dubious items.
            $r = $this->checkSpam($text, [ Spam::ACTION_REVIEW, Spam::ACTION_SPAM ]);
            if ($r) {
                return ($r);
            }

            $r = $this->checkSpam($subj, [ Spam::ACTION_REVIEW, Spam::ACTION_SPAM ]);
            if ($r) {
                return ($r);
            }
        }

        # It's fine.  So far as we know.
        return(NULL);
    }

    private function getSpamWords() {
        if (!$this->spamwords) {
            $this->spamwords = $this->dbhr->preQuery("SELECT * FROM spam_keywords;");
        }
    }

    public function checkReview($message, $language) {
        # Spammer trick is to encode the dot in URLs.
        $message = str_replace('&#12290;', '.', $message);

        #error_log("Check review $message len " . strlen($message));
        if (strlen($message) == 0) {
            # Blank is odd, but not spam.
            return NULL;
        }

        $check = NULL;

        if (!$check && stripos($message, '<script') !== FALSE) {
            # Looks dodgy.
            $check = self::REASON_SCRIPT;
        }

        if (!$check) {
            # Check for URLs.
            if (stripos($message,Spam::URL_REMOVED) !== FALSE) {
                # A URL which has been removed.
                $check = self::REASON_LINK;
            } else if (preg_match_all(Utils::URL_PATTERN, $message, $matches)) {
                # A link.  Some domains are ok - where they have been whitelisted several times (to reduce bad whitelists).
                $ourdomains = $this->dbhr->preQuery("SELECT domain FROM spam_whitelist_links WHERE count >= 3 AND LENGTH(domain) > 5 AND domain NOT LIKE '%linkedin%' AND domain NOT LIKE '%goo.gl%' AND domain NOT LIKE '%bit.ly%' AND domain NOT LIKE '%tinyurl%';");

                $valid = 0;
                $count = 0;
                $badurl = NULL;

                foreach ($matches as $val) {
                    foreach ($val as $url) {
                        $bad = FALSE;
                        $url2 = str_replace('http:', '', $url);
                        $url2 = str_replace('https:', '', $url2);
                        foreach (Utils::URL_BAD as $badone) {
                            if (strpos($url2, $badone) !== FALSE) {
                                $bad = TRUE;
                            }
                        }

                        if (!$bad && strlen($url) > 0) {
                            $url = substr($url, strpos($url, '://') + 3);
                            $count++;
                            $trusted = FALSE;

                            foreach ($ourdomains as $domain) {
                                if (stripos($url, $domain['domain']) === 0) {
                                    # One of our domains.
                                    $valid++;
                                    $trusted = TRUE;
                                }
                            }

                            $badurl = $trusted ? $badurl : $url;
                        }
                    }
                }

                if ($valid < $count) {
                    # At least one URL which we don't trust.
                    $check = self::REASON_LINK;
                }
            }
        }

        if (!$check) {
            # Check keywords
            $this->getSpamWords();
            foreach ($this->spamwords as $word) {
                $w = $word['type'] == 'Literal' ? preg_quote($word['word']) : $word['word'];

                if ($word['action'] == 'Review' &&
                    preg_match('/\b' . $w . '\b/i', $message) &&
                    (!$word['exclude'] || !preg_match('/' . $word['exclude'] . '/i', $message))) {
                    $check = self::REASON_KNOWN_KEYWORD;
                }
            }
        }

        if (!$check && (strpos($message, '$') !== FALSE || strpos($message, '£') !== FALSE || strpos($message, '(a)') !== FALSE)) {
            $check = self::REASON_MONEY;
        }

        # Email addresses are suspect too; a scammer technique is to take the conversation offlist.
        if (!$check && preg_match_all(Message::EMAIL_REGEXP, $message, $matches)) {
            foreach ($matches as $val) {
                foreach ($val as $email) {
                    if (!Mail::ourDomain($email) && strpos($email, 'trashnothing') === FALSE && strpos($email, 'yahoogroups') === FALSE) {
                        $check = self::REASON_EMAIL;
                    }
                }
            }
        }

        if (!$check && $this->checkReferToSpammer($message)) {
            $check = self::REASON_REFERRED_TO_SPAMMER;
        }

        if (!$check && $language) {
            # Check language is English.  This isn't out of some kind of misplaced nationalistic fervour, but just
            # because our spam filters work less well on e.g. French.
            #
            # Short strings like 'test' or 'ok thanks' or 'Eileen', don't always come out as English, so only check
            # slightly longer messages where the identification is more likely to work.
            #
            # We check that English is the most likely, or fairly likely compared to the one chosen.
            #
            # This is a fairly lax test but spots text which is very probably in another language.
            $message = str_ireplace('xxx', '', strtolower(trim($message)));

            if (strlen($message) > 50) {
                $ld = new Language;
                $lang = $ld->detect($message)->close();
                reset($lang);
                $firstlang = key($lang);
                $firstprob = Utils::presdef($firstlang, $lang, 0);
                $enprob = Utils::presdef('en', $lang, 0);
                $cyprob = Utils::presdef('cy', $lang, 0);
                $ourprob = max($enprob, $cyprob);

                $check = !($firstlang == 'en' || $firstlang == 'cy' || $ourprob >= 0.8 * $firstprob);

                if ($check) {
                    $check = self::REASON_LANGUAGE;
                }
            }
        }

        return($check);
    }

    public function checkSpam($message, $actions) {
        $ret = NULL;

        # Strip out any job text, which might have spam keywords.
        $message = preg_replace('/\<https\:\/\/www\.ilovefreegle\.org\/jobs\/.*\>.*$/im', '', $message);

        # Some spammers use HTML entities in text bodyparts to disguise words.
        $message = str_replace('&#616;', 'i', $message);
        $message = str_replace('&#537;', 's', $message);
        $message = str_replace('&#206;', 'I', $message);
        $message = str_replace('=C2', '£', $message);

        # Check keywords which are known as spam.
        $this->getSpamWords();
        foreach ($this->spamwords as $word) {
            if (strlen(trim($word['word'])) > 0) {
                $exp = '/\b' . preg_quote($word['word']) . '\b/i';
                if (in_array($word['action'], $actions) &&
                    preg_match($exp, $message) &&
                    (!$word['exclude'] || !preg_match('/' . $word['exclude'] . '/i', $message))) {
                    $ret = array(true, Spam::REASON_KNOWN_KEYWORD, "Refers to keyword '{$word['word']}'");
                }
            }
        }

        # Check whether any URLs are in Spamhaus DBL black list.
        if (preg_match_all(Utils::URL_PATTERN, $message, $matches)) {
            $checked = [];

            foreach ($matches as $val) {
                foreach ($val as $url) {
                    $bad = FALSE;
                    $url2 = str_replace('http:', '', $url);
                    $url2 = str_replace('https:', '', $url2);
                    foreach (Utils::URL_BAD as $badone) {
                        if (strpos($url2, $badone) !== FALSE) {
                            $bad = TRUE;
                        }
                    }

                    if (!$bad && strlen($url) > 0) {
                        $url = substr($url, strpos($url, '://') + 3);

                        if (array_key_exists($url, $checked)) {
                            # We do this part for performance and part because we've seen hangs in dns_get_record
                            # when checking Spamhaus repeatedly in UT.
                            $ret = $checked[$url];
                        }

                        if (Mail::checkSpamhaus("http://$url")) {
                            $ret = [ TRUE, Spam::REASON_DBL, "Blacklisted url $url" ];
                            $checked[$url] = $ret;
                        }

                        if (preg_match('/.+' . GROUP_DOMAIN . '/', $url) || preg_match('/.+' . USER_DOMAIN . '/', $url)) {
                            # A domain which embeds one of ours in an attempt to fool us into thinking it is legit.
                            $ret = [ TRUE, Spam::REASON_USED_OUR_DOMAIN, "Used our domain inside $url" ] ;
                            $checked[$url] = $ret;
                        }
                    }
                }
            }
        }

        return($ret);
    }

    public function checkReferToSpammer($text) {
        $ret = NULL;

        if (strpos($text, '@') !== FALSE) {
            # Check if it contains a reference to a known spammer.
            if (preg_match_all(Message::EMAIL_REGEXP, $text, $matches)) {
                foreach ($matches as $val) {
                    foreach ($val as $email) {
                        $spammers = $this->dbhr->preQuery("SELECT users_emails.email FROM spam_users INNER JOIN users_emails ON spam_users.userid = users_emails.userid WHERE collection = ? AND email LIKE ?;", [
                            Spam::TYPE_SPAMMER,
                            $email
                        ]);

                        $ret = count($spammers) > 0 ? $spammers[0]['email'] : NULL;

                        if ($ret) {
                            break;
                        }
                    }
                }
            }
        }

        return($ret);
    }

    public function notSpamSubject($subj) {
        $sql = "INSERT IGNORE INTO spam_whitelist_subjects (subject, comment) VALUES (?, 'Marked as not spam');";
        $this->dbhm->preExec($sql, [ $subj ]);
    }

    public function checkUser($userid, $groupJustAdded, $lat = NULL, $lng = NULL, $checkmemberships = TRUE) {
        # Called when something has happened to a user which makes them more likely to be a spammer, and therefore
        # needs rechecking.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $u = User::get($this->dbhr, $this->dbhm, $userid);

        if ($u->getId() == $userid && $u->isModerator()) {
            # We whitelist all mods.
            return FALSE;
        }

        $suspect = FALSE;
        $reason = NULL;
        $suspectgroups = [];

        if ($checkmemberships) {
            # Check whether they have applied to a suspicious number of groups, but exclude whitelisted members.
            #
            # If we have just added a membership then it may not have been logged yet, so we might fail to count
            # it.  This happens in UT.
            $groupq = $groupJustAdded ? " AND logs.groupid != $groupJustAdded " : '';
            $start = date('Y-m-d', strtotime("365 days ago"));

            $sql = "SELECT COUNT(DISTINCT(groupid)) AS count FROM logs LEFT JOIN spam_users ON spam_users.userid = logs.user AND spam_users.collection = ? WHERE logs.user = ? AND logs.type = ? AND logs.subtype = ? AND spam_users.userid IS NULL $groupq AND logs.timestamp >= ?;";
            $counts = $this->dbhr->preQuery($sql, [
                Spam::TYPE_WHITELIST,
                $userid,
                Log::TYPE_GROUP,
                Log::SUBTYPE_JOINED,
                $start
            ]);

            $count = $counts[0]['count'];

            if ($groupJustAdded) {
                $count++;
            }

            if ($count > Spam::SEEN_THRESHOLD) {
                $suspect = true;
                $reason = "Seen on many groups";
            }
        }

        if (!$suspect) {
            list($suspect, $reason, $suspectgroups) = $this->checkReplyDistance(
                $userid,
                $lat,
                $lng,
            );
        }

        if ($suspect) {
            # This user is suspect.  We will mark it as so, which means that it'll show up to mods on relevant groups,
            # and they will review it.
            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_SUSPECT,
                'byuser' => $me ? $me->getId() : NULL,
                'user' => $userid,
                'text' => $reason
            ]);

            $memberships = $u->getMemberships();

            foreach ($memberships as $membership) {
                if (!count($suspectgroups) || in_array($membership['id'], $suspectgroups)) {
                    $u->memberReview($membership['id'], TRUE, $reason);
                }
            }

            User::clearCache($userid);
        }

        return $suspect;
    }

    public function collectionCounts() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $ret = [
            Spam::TYPE_PENDING_ADD => 0,
            Spam::TYPE_PENDING_REMOVE => 0
        ];

        if ($me && $me->hasPermission(User::PERM_SPAM_ADMIN)) {
            $sql = "SELECT COUNT(*) AS count, collection FROM spam_users WHERE collection IN (?, ?) GROUP BY collection;";
            $counts = $this->dbhr->preQuery(
                $sql,
                [
                    Spam::TYPE_PENDING_ADD,
                    Spam::TYPE_PENDING_REMOVE
                ]
            );

            foreach ($counts as $count) {
                $ret[$count['collection']] = $count['count'];
            }
        }

        return($ret);
    }

    public function exportSpammers() {
        $sql = "SELECT spam_users.id, spam_users.added, reason, email FROM spam_users INNER JOIN users_emails ON spam_users.userid = users_emails.userid WHERE collection = ?;";
        $spammers = $this->dbhr->preQuery($sql, [ Spam::TYPE_SPAMMER ]);
        return($spammers);
    }

    public function listSpammers($collection, $search, &$context) {
        # We exclude anyone who isn't a User (e.g. mods, support, admin) so that they don't appear on the list and
        # get banned.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $seeall = $me && $me->isAdminOrSupport();
        $collectionq = ($collection ? " AND collection = '$collection'" : '');
        $startq = $context ? (" AND spam_users.id <  " . intval($context['id']) . " ") : '';
        $searchq = is_null($search) ? '' : (" AND (users_emails.email LIKE " . $this->dbhr->quote("%$search%") . " OR users.fullname LIKE " . $this->dbhr->quote("%$search%") . ") ");
        $sql = "SELECT DISTINCT spam_users.* FROM spam_users INNER JOIN users ON spam_users.userid = users.id LEFT JOIN users_emails ON users_emails.userid = spam_users.userid WHERE 1=1 $startq $collectionq $searchq ORDER BY spam_users.id DESC LIMIT 10;";
        $context = [];

        $spammers = $this->dbhr->preQuery($sql);
        $u = new User($this->dbhr, $this->dbhm);
        $spammeruids = array_filter(array_unique(array_column($spammers, 'userid')));
        $users = $u->getPublicsById($spammeruids, NULL, TRUE, $seeall);
        $ctx = NULL;
        $moduids = array_filter(array_unique(array_merge(array_column($spammers, 'byuserid'), array_column($spammers, 'heldby'))));
        $users2 = $u->getPublicsById($moduids, NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
        $users = array_replace($users, $users2);
        $emails = $u->getEmailsById(array_merge($spammeruids, $moduids));

        foreach ($spammers as &$spammer) {
            $spammer['user'] = $users[$spammer['userid']];

            $es = Utils::presdef($spammer['userid'], $emails, []);

            foreach ($es as $anemail) {
                if (!Utils::pres('email', $spammer['user']) && !Mail::ourDomain($anemail['email']) && strpos($anemail['email'], '@yahoogroups.') === FALSE) {
                    $spammer['user']['email'] = $anemail['email'];
                }
            }

            $others = [];

            foreach ($es as $anemail) {
                if ($anemail['email'] != $spammer['user']['email']) {
                    $others[] = $anemail;
                }
            }

            uasort($others, function($a, $b) {
                return(strcmp($a['email'], $b['email']));
            });

            $spammer['user']['otheremails'] = $others;

            if ($spammer['byuserid']) {
                $spammer['byuser'] = $users[$spammer['byuserid']];

                if ($me->isModerator()) {
                    $es = Utils::presdef($spammer['byuserid'], $emails, []);

                    foreach ($es as $anemail) {
                        if ($anemail['email'] != $spammer['user']['email']) {
                            $others[] = $anemail;
                        }

                        if (!Utils::pres('email', $spammer['byuser']) && !Mail::ourDomain($anemail['email']) && strpos($anemail['email'], '@yahoogroups.') === FALSE) {
                            $spammer['byuser']['email'] = $anemail['email'];
                        }
                    }
                }
            }

            if ($collection ==  Spam::TYPE_PENDING_ADD) {
                if (Utils::pres('heldby', $spammer)) {
                    $spammer['user']['heldby'] = $users[$spammer['heldby']];
                    $spammer['user']['heldat'] = Utils::ISODate($spammer['heldat']);
                    unset($spammer['heldby']);
                    unset($spammer['heldat']);
                }
            }

            $spammer['added'] = Utils::ISODate($spammer['added']);

            # Add in any other users who have recently used the same IP.  But not for TN users, because
            # the TN servers use our API for multiple users.
            $spammer['sameip'] = [];

            if (strpos($spammer['user']['email'], '@user.trashnothing.com') === FALSE) {
                $ips = $this->dbhr->preQuery("SELECT DISTINCT(ip) FROM logs_api WHERE userid = ?;", [
                    $spammer['userid']
                ]);

                foreach ($ips as $ip) {
                    $otherusers = $this->dbhr->preQuery("SELECT DISTINCT userid FROM logs_api WHERE ip = ? AND userid != ?;", [
                        $ip['ip'],
                        $spammer['userid']
                    ]);

                    foreach ($otherusers as $otheruser) {
                        $spammer['sameip'][] = $otheruser['userid'];
                    }
                }

                $spammer['sameip'] = array_unique($spammer['sameip']);
            }

            $context['id'] = $spammer['id'];
        }

        return($spammers);
    }

    public function getSpammer($id) {
        $sql = "SELECT * FROM spam_users WHERE id = ?;";
        $ret = NULL;

        $spams = $this->dbhr->preQuery($sql, [ $id ]);

        foreach ($spams as $spam) {
            $ret = $spam;
        }

        return($ret);
    }

    public function getSpammerByUserid($userid, $collection = Spam::TYPE_SPAMMER) {
        $sql = "SELECT * FROM spam_users WHERE userid = ? AND collection = ?;";
        $ret = NULL;

        $spams = $this->dbhr->preQuery($sql, [ $userid, $collection ]);

        foreach ($spams as $spam) {
            $ret = $spam;
        }

        return($ret);
    }

    public function removeSpamMembers($groupid = NULL) {
        $count = 0;
        $groupq = $groupid ? " AND groupid = $groupid " : "";

        # Find anyone in the spammer list with a current (approved or pending) membership.  Don't remove mods
        # in case someone wrongly gets onto the list.
        $sql = "SELECT * FROM memberships INNER JOIN spam_users ON memberships.userid = spam_users.userid AND spam_users.collection = ? AND memberships.role = 'Member' $groupq;";
        $spammers = $this->dbhr->preQuery($sql, [ Spam::TYPE_SPAMMER ]);

        foreach ($spammers as $spammer) {
            error_log("Found spammer {$spammer['userid']}");
            $u = User::get($this->dbhr, $this->dbhm, $spammer['userid']);
            error_log("Remove spammer {$spammer['userid']}");
            $u->removeMembership($spammer['groupid'], TRUE, TRUE);
            $count++;
        }

        # Find any messages from spammers which are on groups.
        $groupq = $groupid ? " AND messages_groups.groupid = $groupid " : "";
        $sql = "SELECT DISTINCT messages.id, reason, messages_groups.groupid FROM `messages` INNER JOIN spam_users ON messages.fromuser = spam_users.userid AND spam_users.collection = ? AND messages.deleted IS NULL INNER JOIN messages_groups ON messages.id = messages_groups.msgid INNER JOIN users ON messages.fromuser = users.id AND users.systemrole = 'User' $groupq;";
        $spammsgs = $this->dbhr->preQuery($sql, [
            Spam::TYPE_SPAMMER
        ]);

        foreach ($spammsgs as $spammsg) {
            error_log("Found spam message {$spammsg['id']}");
            $m = new Message($this->dbhr, $this->dbhm, $spammsg['id']);
            $m->delete("From known spammer {$spammsg['reason']}");
            $count++;
        }

        # Find any chat messages from spammers.
        $chats = $this->dbhr->preQuery("SELECT id, chatid FROM chat_messages WHERE 
userid IN (SELECT userid FROM spam_users WHERE collection = 'Spammer')
AND reviewrejected != 1;");
        foreach ($chats as $chat) {
            error_log("Found spam chat message {$chat['id']}");
            $sql = "UPDATE chat_messages SET reviewrejected = 1, reviewrequired = 0 WHERE id = ?";
            $this->dbhm->preExec($sql, [ $chat['id'] ]);
        }

        # Delete any newsfeed items from spammers.
        $newsfeeds = $this->dbhr->preQuery("SELECT id FROM newsfeed WHERE userid IN (SELECT userid FROM spam_users WHERE collection = 'Spammer');");
        foreach ($newsfeeds as $newsfeed) {
            error_log("Delete newsfeed item {$newsfeed['id']}");
            $sql = "DELETE FROM newsfeed WHERE id = ?;";
            $this->dbhm->preExec($sql, [ $newsfeed['id'] ]);
        }

        # Delete any notifications from spammers
        $notifs = $this->dbhr->preQuery("SELECT id FROM users_notifications WHERE fromuser IN (SELECT userid FROM spam_users WHERE collection = 'Spammer');");
        foreach ($notifs as $notif) {
            error_log("Delete notification {$notif['id']}");
            $sql = "DELETE FROM users_notifications WHERE id = ?;";
            $this->dbhm->preExec($sql, [ $notif['id'] ]);
        }

        # Remove any cases where the spammer has said they're waiting for a reply, which makes the spammee look
        # bad.
        $expecteds = $this->dbhr->preQuery("SELECT users_expected.id FROM `users_expected` INNER JOIN spam_users ON expecter = spam_users.userid AND collection = 'Spammer';");
        foreach ($expecteds as $expected) {
            error_log("Delete expected {$expected['id']}");
            $this->dbhm->preExec("DELETE FROM users_expected WHERE id = ?;", [
                $expected['id']
            ]);
        }

        # Delete any sessions for spammers.
        $sessions = $this->dbhr->preQuery("SELECT sessions.id, sessions.userid FROM sessions INNER JOIN 
    spam_users ON spam_users.userid = sessions.userid WHERE sessions.userid IS NOT NULL AND collection = ?;", [
            Spam::TYPE_SPAMMER
        ]);

        foreach ($sessions as $session) {
            error_log("Delete session {$session['id']} for {$session['userid']}");
            $this->dbhm->preExec("DELETE FROM sessions WHERE id = ?;", [
                $session['id']
            ]);
        }

        return($count);
    }

    public function addSpammer($userid, $collection, $reason) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $text = NULL;
        $id = NULL;

        switch ($collection) {
            case Spam::TYPE_WHITELIST: {
                $text = "Whitelisted: $reason";

                # Ensure nobody who is whitelisted is banned.
                $this->dbhm->preExec("DELETE FROM users_banned WHERE userid IN (SELECT userid FROM spam_users WHERE collection = ?);", [
                    Spam::TYPE_WHITELIST
                ]);
                break;
            }
            case Spam::TYPE_PENDING_ADD: {
                $text = "Reported: $reason";

                # We set the newsfeed status of any reported user to 'Suppressed', to reduce vandalism.
                $u = new User($this->dbhr, $this->dbhm, $userid);
                if ($u->getPrivate('systemrole') == User::SYSTEMROLE_USER) {
                    $u->setPrivate('newsfeedmodstatus', User::NEWSFEED_SUPPRESSED);
                }

                break;
            }
        }

        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_SUSPECT,
            'byuser' => $me ? $me->getId() : NULL,
            'user' => $userid,
            'text' => $text
        ]);

        $proceed = TRUE;

        if ($collection == Spam::TYPE_PENDING_ADD) {
            # We don't want to overwrite an existing entry in the spammer list just because someone tries to
            # report it again.
            $u = new User($this->dbhr, $this->dbhm, $userid);

            $ourDomain = FALSE;

            foreach ($u->getEmails() as $email) {
                if (strpos($email['email'], USER_DOMAIN) === FALSE && $email['ourdomain']) {
                    # Don't report spammers on our own domains.  They will be spoofed.
                    $proceed = FALSE;
                }
            }

            if ($proceed) {
                $spammers = $this->dbhr->preQuery("SELECT * FROM spam_users WHERE userid = ?;", [ $userid ]);
                foreach ($spammers as $spammer) {
                    $proceed = FALSE;
                }
            }
        }

        if ($proceed) {
            $sql = "REPLACE INTO spam_users (userid, collection, reason, byuserid, heldby, heldat) VALUES (?,?,?,?, NULL, NULL);";
            $rc = $this->dbhm->preExec($sql, [
                $userid,
                $collection,
                $reason,
                $me ? $me->getId() : NULL
            ]);

            $id = $rc ? $this->dbhm->lastInsertId() : NULL;
        }

        return($id);
    }

    public function updateSpammer($id, $userid, $collection, $reason, $heldby) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        switch ($collection) {
            case Spam::TYPE_SPAMMER: {
                $text = "Confirmed as spammer";
                break;
            }
            case Spam::TYPE_WHITELIST: {
                $text = "Whitelisted: $reason";
                break;
            }
            case Spam::TYPE_PENDING_ADD: {
                $text = "Reported: $reason";
                break;
            }
            case Spam::TYPE_PENDING_REMOVE: {
                $text = "Requested removal: $reason";
                break;
            }
        }

        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_SUSPECT,
            'byuser' => $me ? $me->getId() : NULL,
            'user' => $userid,
            'text' => $text . ($heldby ? (", held $heldby") : '')
        ]);

        # Don't want to lose any existing reason, but update the user when removal is requested so that we
        # know who's asking.
        $spammers = $this->dbhr->preQuery("SELECT * FROM spam_users WHERE id = ?;", [ $id ]);
        foreach ($spammers as $spammer) {
            $sql = "UPDATE spam_users SET collection = ?, reason = ?, byuserid = ?, heldby = ?, heldat = CASE WHEN ? IS NOT NULL THEN NOW() ELSE NULL END WHERE id = ?;";
            $rc = $this->dbhm->preExec($sql, [
                $collection,
                $reason ? $reason : $spammer['reason'],
                $collection == Spam::TYPE_PENDING_REMOVE && $me ? $me->getId() : $spammer['byuserid'],
                $heldby,
                $heldby,
                $id
            ]);
        }

        $id = $rc ? $this->dbhm->lastInsertId() : NULL;

        return($id);
    }

    public function deleteSpammer($id, $reason) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $spammers = $this->dbhr->preQuery("SELECT * FROM spam_users WHERE id = ?;", [ $id ]);

        $rc = FALSE;

        foreach ($spammers as $spammer) {
            $rc = $this->dbhm->preExec("DELETE FROM spam_users WHERE id = ?;", [
                $id
            ]);

            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_SUSPECT,
                'byuser' => $me ? $me->getId() : NULL,
                'user' => $spammer['userid'],
                'text' => "Removed: $reason"
            ]);
        }

        return($rc);
    }

    public function isSpammer($email) {
        $ret = FALSE;

        if ($email) {
            $u = new User($this->dbhr, $this->dbhm);
            $uid = $u->findByEmail($email);

            if ($uid) {
                $ret = $this->isSpammerUid($uid);
            }
        }

        return($ret);
    }

    public function isSpammerUid($uid, $collection = Spam::TYPE_SPAMMER) {
        $ret = FALSE;

        $spammers = $this->dbhr->preQuery("SELECT id FROM spam_users WHERE userid = ? AND collection = ?;", [
            $uid,
            $collection
        ]);

        foreach ($spammers as $spammer) {
            $ret = TRUE;
        }

        return($ret);
    }

    public function checkReplyDistance(
        $userid,
        $lat,
        $lng,
    ) {
        # Check if they've replied to multiple posts across a wide area recently.  Ignore any messages outside
        # a bounding box for the UK, because then it's those messages that are suspicious, and this member the
        # poor sucker who they are trying to scam.
        $suspect = FALSE;
        $since = date('Y-m-d', strtotime("midnight 90 days ago"));
        $dists = $this->dbhm->preQuery(
            "SELECT DISTINCT MAX(messages.lat) AS maxlat, MIN(messages.lat) AS minlat, MAX(messages.lng) AS maxlng, MIN(messages.lng) AS minlng, groups.id AS groupid, groups.nameshort, groups.settings FROM chat_messages 
    INNER JOIN messages ON messages.id = chat_messages.refmsgid 
    INNER JOIN messages_groups ON messages_groups.msgid = messages.id
    INNER JOIN `groups` ON groups.id = messages_groups.groupid
    WHERE userid = ? AND chat_messages.date >= ? AND chat_messages.type = ? AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL AND
     messages.lng >= -7.57216793459 AND messages.lat >= 49.959999905 AND messages.lng <= 1.68153079591 AND messages.lat <= 58.6350001085;",
            [
                $userid,
                $since,
                ChatMessage::TYPE_INTERESTED
            ]
        );

        if (($dists[0]['maxlat'] || $dists[0]['minlat'] || $dists[0]['maxlng'] || $dists[0]['minlng']) && ($lat || $lng)) {
            # Add the lat/lng we're interested in into the mix.
            $maxlat = max($dists[0]['maxlat'], $lat);
            $minlat = min($dists[0]['minlat'], $lat);
            $maxlng = max($dists[0]['maxlng'], $lng);
            $minlng = min($dists[0]['minlng'], $lng);

            $dist = \GreatCircle::getDistance($minlat, $minlng, $maxlat, $maxlng);
            $dist = round($dist * 0.000621371192);
            $settings = Utils::pres('settings', $dists[0]) ? json_decode($dists[0]['settings'], true) : [
                'spammers' => [
                    'replydistance' => Spam::DISTANCE_THRESHOLD
                ]
            ];

            $replydist = array_key_exists('spammers', $settings) && array_key_exists(
                'replydistance',
                $settings['spammers']
            ) ? $settings['spammers']['replydistance'] : Spam::DISTANCE_THRESHOLD;

            error_log("...compare $dist vs $replydist for group {$dists[0]['groupid']} settings " . json_encode($settings['spammers']));

            if ($replydist > 0 && $dist >= $replydist) {
                # Check if it is greater than the current distance, so we don't keep asking for the same user
                $rounded = round($dist / 5) * 5;
                $existing = $this->dbhr->preQuery("SELECT replyambit FROM users WHERE id = ?;", [
                    $userid
                ]);

                if ($rounded > $existing[0]['replyambit']) {
                    $this->dbhm->preExec("UPDATE users SET replyambit = ? WHERE id = ?;", [
                        $rounded,
                        $userid
                    ]);

                    $suspect = true;
                    $reason = "Replied to posts $dist miles apart (threshold on {$dists[0]['nameshort']} $replydist)";
                    $suspectgroups[] = $dists[0]['groupid'];
                }
            }
        }

        return [ $suspect, $reason, $suspectgroups ];
    }
}