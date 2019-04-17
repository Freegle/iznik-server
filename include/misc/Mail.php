<?php

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
        Mail::SPAM_WARNING => 'SpamWarning'
    ];

    # This is the key control over how frequency we add Return Path seed lists to our mails.  0 will disable.
    const RETURNPATH_THRESHOLDS = [
        Mail::DIGEST => 0,
        Mail::CHAT => 1000,
        Mail::REMOVED => 0,
        Mail::THANK_DONATION => 0,
        Mail::INVITATION => 0,
        Mail::ASK_DONATION => 0,
        Mail::DONATE_IPN => 0,
        Mail::CHAT_CHASEUP_MODS => 0,
        Mail::ADMIN => 0,
        Mail::ALERT => 0,
        Mail::DIGEST_OFF => 0,
        Mail::EVENTS => 0,
        Mail::EVENTS_OFF => 0,
        Mail::NEWSLETTER => 0,
        Mail::NEWSLETTER_OFF => 0,
        Mail::RELEVANT => 0,
        Mail::RELEVANT_OFF => 0,
        Mail::VOLUNTEERING => 0,
        Mail::VOLUNTEERING_OFF => 0,
        Mail::VOLUNTEERING_RENEW => 0,
        Mail::NEWSFEED => 0,
        Mail::NEWSFEED_OFF => 0,
        Mail::NEWSFEED_MODNOTIF => 0,
        Mail::NEARBY => 0,
        Mail::NOTIFICATIONS => 0,
        Mail::NOTIFICATIONS_OFF => 0,
        Mail::REQUEST => 0,
        Mail::REQUEST_COMPLETED => 0,
        Mail::STORY => 0,
        Mail::STORY_OFF => 0,
        Mail::STORY_ASK => 0,
        Mail::WELCOME => 0,
        Mail::FORGOT_PASSWORD => 0,
        Mail::VERIFY_EMAIL => 0,
        Mail::BAD_SMS => 0,
        Mail::SPAM_WARNING => 0
    ];

    public static function getDescription($type) {
        return(Mail::DESCRIPTIONS[$type]);
    }

    public static function shouldSend($type) {
        return(mt_rand(0, 1000) < Mail::RETURNPATH_THRESHOLDS[$type]);
    }

    public static function matchingId($type, $qualifier) {
        # Return Path is picky about the format - needs to be alphabetic then number.
        #
        # It also applies a limit per month so use something which will only change every week.  That way all our
        # mails of a particular type and qualifier will be grouped.
        $matchingid = 'freegle' . $type. str_pad($qualifier < 0 ? (100 + $qualifier) : $qualifier, 3, '0') . date("Ymd000000", strtotime('Last Sunday'));
        return($matchingid);
    }

    public static function addHeaders($msg, $type, $userid = 0, $qualifier = 0) {
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
}