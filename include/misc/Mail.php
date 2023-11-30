<?php
namespace Freegle\Iznik;

# This holds info about the different kinds of mails we send.
class Mail {
    const DIGEST = 1;
    const CHAT = 2;
    const REMOVED = 4;
    const THANK_DONATION = 5;
    const INVITATION = 6;
    const ASK_DONATION = 7;
    const DONATE_IPN = 8;
    const CHAT_CHASEUP_MODS = 9;
    const ADMIN = 10;
    const ALERT = 11;
    const NEARBY = 12;
    const DIGEST_OFF = 13;
    const EVENTS = 14;
    const EVENTS_OFF = 15;
    const NEWSLETTER = 16;
    const NEWSLETTER_OFF = 17;
    const RELEVANT = 18;
    const RELEVANT_OFF = 19;
    const VOLUNTEERING = 20;
    const VOLUNTEERING_OFF = 21;
    const VOLUNTEERING_RENEW = 22;
    const NEWSFEED = 23;
    const NEWSFEED_OFF = 24;
    const NEWSFEED_MODNOTIF = 25;
    const NOTIFICATIONS = 26;
    const NOTIFICATIONS_OFF = 27;
    const REQUEST = 28;
    const REQUEST_COMPLETED = 29;
    const STORY = 30;
    const STORY_OFF = 31;
    const STORY_ASK = 32;
    const WELCOME = 33;
    const FORGOT_PASSWORD = 34;
    const VERIFY_EMAIL = 35;
    const BAD_SMS = 36;
    const SPAM_WARNING = 37;
    const NOTICEBOARD = 38;
    const MERGE = 39;
    const UNSUBSCRIBE = 40;
    const MISSING = 41;
    const NOTICEBOARD_CHASEUP_OWNER = 42;
    const DONATE_EXTERNAL = 43;
    const REFER_TO_SUPPORT = 44;
    const NOT_A_MEMBER = 46;
    const MODMAIL = 47;
    const AUTOREPOST = 48;
    const CHASEUP = 49;
    const REPORTED_NEWSFEED = 50;
    const CALENDAR = 51;

    const DESCRIPTIONS = [
        Mail::DIGEST => 'Digest',
        Mail::CHAT => 'Chat',
        Mail::REMOVED => 'Removed',
        Mail::THANK_DONATION => 'ThankDonation',
        Mail::INVITATION => 'Invitation',
        Mail::ASK_DONATION => 'AskDonation',
        Mail::DONATE_IPN => 'DonateIPN',
        Mail::CHAT_CHASEUP_MODS => 'ChatChaseupMods',
        Mail::ADMIN => 'Admin',
        Mail::ALERT => 'Alert',
        Mail::DIGEST_OFF => 'MailOff',
        Mail::EVENTS => 'Events',
        Mail::EVENTS_OFF => 'EventsOff',
        Mail::NEWSLETTER => 'Newsletter',
        Mail::NEWSLETTER_OFF => 'NewsletterOff',
        Mail::RELEVANT => 'Relevant',
        Mail::RELEVANT_OFF => 'RelevantOff',
        Mail::VOLUNTEERING => 'Volunteering',
        Mail::VOLUNTEERING_OFF => 'VolunteeringOff',
        Mail::VOLUNTEERING_RENEW => 'VolunteeringRenew',
        Mail::NEWSFEED => 'Newsfeed',
        Mail::NEWSFEED_OFF => 'NewsfeedOff',
        Mail::NEWSFEED_MODNOTIF => 'NewsfeedModNotif',
        Mail::NEARBY => 'Nearby',
        Mail::NOTIFICATIONS => 'Notifications',
        Mail::NOTIFICATIONS_OFF => 'NotificationsOff',
        Mail::REQUEST => 'Request',
        Mail::REQUEST_COMPLETED => 'RequestCompleted',
        Mail::STORY => 'Story',
        Mail::STORY_OFF => 'StoryOff',
        Mail::STORY_ASK => 'StoryOff',
        Mail::WELCOME => 'Welcome',
        Mail::FORGOT_PASSWORD => 'ForgotPassword',
        Mail::VERIFY_EMAIL => 'VerifyEmail',
        Mail::BAD_SMS => 'BadSMS',
        Mail::SPAM_WARNING => 'SpamWarning',
        Mail::NOTICEBOARD => 'Noticeboard',
        Mail::UNSUBSCRIBE => 'Unsubscribe',
        Mail::MISSING => 'Missing',
        Mail::NOTICEBOARD_CHASEUP_OWNER => 'NoticeboardChaseupOwner',
        Mail::REFER_TO_SUPPORT => 'ReferToSupport',
        Mail::MERGE => 'Merge',
        Mail::DONATE_EXTERNAL => 'DonateExternal',
        Mail::NOT_A_MEMBER => 'NotAMember',
        Mail::MODMAIL => 'ModMail',
        Mail::AUTOREPOST => 'AutoRepost',
        Mail::CHASEUP => 'Chaseup',
        Mail::REPORTED_NEWSFEED => 'ReportedNewsfeed',
        Mail::CALENDAR => 'Calendar',
    ];

    private static $mailers = [];

    public static function getDescription($type) {
        return(Mail::DESCRIPTIONS[$type]);
    }

    public static function matchingId($type, $qualifier) {
        # Return Path is picky about the format - needs to be alphabetic then number.
        #
        # It also applies a limit per month so use something which will only change every week.  That way all our
        # mails of a particular type and qualifier will be grouped.
        $matchingid = 'freegle' . $type. str_pad($qualifier < 0 ? (100 + $qualifier) : $qualifier, 3, '0') . date("Ymd000000", strtotime('Last Sunday'));
        return($matchingid);
    }

    public static function addHeaders($dbhr, $dbhm, $msg, $type, $userid = 0, $qualifier = 0) {
        $headers = $msg->getHeaders();

        # We add a header of our own.  TN uses this.
        $headers->addTextHeader('X-Freegle-Mail-Type', Mail::getDescription($type));

        if (RETURN_PATH) {
            # Return path uses X-rpccampaign
            $headers->addTextHeader('X-rpcampaign', Mail::matchingId($type, $qualifier));
        }

        # Google feedback loop uses Feedback-ID as per
        # https://support.google.com/mail/answer/6254652?hl=en&ref_topic=7279058
        $headers->addTextHeader('Feedback-ID', "$qualifier:$userid:" . Mail::getDescription($type) . ':freegle');

        # Add one-click unsubscribe.
        $u = new User($dbhr, $dbhm);
        $headers->addTextHeader('List-Unsubscribe', $u->listUnsubscribe($userid, Mail::getDescription($type)));
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    public static function getSeeds($dbhr, $dbhm) {
        $users = $dbhr->preQuery("SELECT * FROM returnpath_seedlist WHERE active = 1 AND userid IS NOT NULL;");

        foreach ($users as $user) {
            $ret[] = $user['userid'];

            if ($user['oneshot']) {
                $dbhm->preExec("UPDATE returnpath_seedlist SET active = 0 WHERE id = ?;", [
                    $user['id']
                ]);
            }
        }

        return($users);
    }

    public static function getMailer($host = 'localhost', $spoolname = '/spool') {
        $key = $host . $spoolname;

        if (!array_key_exists($key, self::$mailers)) {
            if (!file_exists(IZNIK_BASE . $spoolname)) {
                @mkdir(IZNIK_BASE . $spoolname);
                @chmod(IZNIK_BASE . $spoolname, 755);
                @chgrp(IZNIK_BASE . $spoolname, 'www-data');
                @chown(IZNIK_BASE . $spoolname, 'www-data');
            }

            $spool = new \Swift_FileSpool(IZNIK_BASE . $spoolname);
            $spooltrans = \Swift_SpoolTransport::newInstance($spool);
            $smtptrans = \Swift_SmtpTransport::newInstance($host);
            $transport = \Swift_FailoverTransport::newInstance([
                                                                   $smtptrans,
                                                                   $spooltrans
                                                               ]);
            $mailer = \Swift_Mailer::newInstance($transport);
            self::$mailers[$key] = [$transport, $mailer];
        }

        return self::$mailers[$key];
    }

    public static function realEmail($email) {
        # TODO What's the right way to spot a 'real' address?
        return(
            stripos($email, USER_DOMAIN) === FALSE &&
            stripos($email, 'fbuser') === FALSE &&
            stripos($email, 'trashnothing.com') === FALSE &&
            stripos($email, '@ilovefreegle.org') === FALSE &&
            stripos($email, 'modtools.org') === FALSE
        );
    }

    public static function ourDomain($email) {
        $ourdomains = explode(',', OURDOMAINS);

        $ours = FALSE;
        foreach ($ourdomains as $domain) {
            if (stripos($email, '@' . $domain) !== FALSE) {
                $ours = TRUE;
                break;
            }
        }

        return($ours);
    }

    public static function checkSpamhaus($url) {
        $ret = FALSE;

        if (strpos($url, 'https://goo.gl') === 0) {
            # Google's shortening service.  Fetch.
            try {
                $exp = file_get_contents('https://www.googleapis.com/urlshortener/v1/url?key=' . GOOGLE_VISION_KEY . '&shortUrl=' . $url);

                if ($exp) {
                    $exp = json_decode($exp, TRUE);

                    if ($exp) {
                        if (Utils::pres('longUrl', $exp)) {
                            $url = $exp['longUrl'];
                        }
                    }
                }
            } catch (\Exception $e) {}
        }

        $parsed = parse_url( $url );

        if (isset($parsed['host'])) {
            // Remove www. from domain (but not from www.com)
            $parsed['host'] = preg_replace('/^www\.(.+\.)/i', '$1', $parsed['host']);

            $blacklists = array(
                'dbl.spamhaus.org',
            );

            foreach ($blacklists as $blacklist) {
                $domain = $parsed['host'] . '.' . $blacklist . '.';
                try {
                    $record = dns_get_record($domain, DNS_A);

                    if ($record != NULL && count($record) > 0) {
                        foreach ($record as $entry) {
                            if (array_key_exists('ip', $entry) && strpos($entry['ip'], '127.0.1') === 0) {
                                error_log("Spamhaus blocked $url");
                                $ret = TRUE;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("dns_get_record for $domain failed " . $e->getMessage());
                }
            }
        }

        return $ret;
    }
}