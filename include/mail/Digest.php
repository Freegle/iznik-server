<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/digest/off.php');

class Digest
{
    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;

    private $errorlog;

    const NEVER = 0;
    const IMMEDIATE = -1;
    const HOUR1 = 1;
    const HOUR2 = 2;
    const HOUR4 = 4;
    const HOUR8 = 8;
    const DAILY = 24;

    function __construct($dbhr, $dbhm, $id = NULL, $errorlog = FALSE)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($this->dbhr, $this->dbhm);
        $this->errorlog = $errorlog;
        
        $this->freqText = [
            Digest::NEVER => 'never',
            Digest::IMMEDIATE => 'immediately',
            Digest::HOUR1 => 'every hour',
            Digest::HOUR2 => 'every two hours',
            Digest::HOUR4 => 'every four hours',
            Digest::HOUR8 => 'every eight hours',
            Digest::DAILY => 'daily'
        ];
    }

    # Split out for UT to override
    public function sendOne($mailer, $message) {
        if (RETURN_PATH && Mail::shouldSend(Mail::DIGEST)) {
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

    public function off($uid, $groupid) {
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        if ($u->getId() == $uid) {
            if ($u->isApprovedMember($groupid)) {
                $u->setMembershipAtt($groupid, 'emailfrequency', 0);
                $g = Group::get($this->dbhr, $this->dbhm, $groupid);

                # We can receive messages for emails from the old system where the group id is no longer valid.
                if ($g->getId() == $groupid) {
                    $groupname = $g->getPublic()['namedisplay'];

                    $this->log->log([
                        'type' => Log::TYPE_USER,
                        'subtype' => Log::SUBTYPE_MAILOFF,
                        'user' => $uid,
                        'groupid' => $groupid
                    ]);

                    $email = $u->getEmailPreferred();
                    if ($email) {
                        list ($transport, $mailer) = Mail::getMailer();
                        $html = digest_off(USER_SITE, USERLOGO, $groupname);

                        $message = \Swift_Message::newInstance()
                            ->setSubject("Email Change Confirmation")
                            ->setFrom([NOREPLY_ADDR => 'Do Not Reply'])
                            ->setReturnPath("bounce-$uid-" . time() . "@" . USER_DOMAIN)
                            ->setTo([$email => $u->getName()])
                            ->setBody("We've turned your emails off on $groupname.");

                        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                        # Outlook.
                        $htmlPart = \Swift_MimePart::newInstance();
                        $htmlPart->setCharset('utf-8');
                        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                        $htmlPart->setContentType('text/html');
                        $htmlPart->setBody($html);
                        $message->attach($htmlPart);

                        Mail::addHeaders($message, Mail::DIGEST_OFF, $uid);

                        $this->sendOne($mailer, $message);
                    }
                }
            }
        }
    }

    public function send($groupid, $frequency, $host = 'localhost', $uidforce = NULL) {
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);
        $sent = 0;

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);

        # Don't send digests for closed groups.
        if (!$g->getSetting('closed', FALSE)) {
            $gatts = $g->getPublic();
            $sponsors = $g->getSponsorships();

            if ($this->errorlog) { error_log("#$groupid " . $g->getPrivate('nameshort') . " send emails for $frequency"); }

            # Make sure we have a tracking entry.
            $sql = "INSERT IGNORE INTO groups_digests (groupid, frequency) VALUES (?, ?);";
            $this->dbhm->preExec($sql, [ $groupid, $frequency ]);

            $sql = "SELECT TIMESTAMPDIFF(MINUTE, started, NOW()) AS timeago, groups_digests.* FROM groups_digests WHERE groupid = ? AND frequency = ? " . ($uidforce ? '' : 'HAVING frequency = -1 OR timeago IS NULL OR timeago >= frequency * 60') . ";";
            #error_log("Look for groups to process $sql, $groupid, $frequency");
            $tracks = $this->dbhr->preQuery($sql, [ $groupid, $frequency ]);

            $tz1 = new \DateTimeZone('UTC');
            $tz2 = new \DateTimeZone('Europe/London');

            foreach ($tracks as $track) {
                if ($this->errorlog) { error_log("Start group $groupid"); }
                $sql = "UPDATE groups_digests SET started = NOW() WHERE groupid = ? AND frequency = ?;";
                $this->dbhm->preExec($sql, [$groupid, $frequency]);

                # Find the cut-off time for the earliest message we want to include.  If we've not sent anything for this
                # group/frequency before then ensure we don't send anything older than a day the first time. And never
                # send anything older than 30 days, that's just silly.
                $oldest  = Utils::pres('ended', $track) ? '' : " AND arrival >= '" . date("Y-m-d H:i:s", strtotime("24 hours ago")) . "'";
                $oldest .=  " AND arrival >= '" . date("Y-m-d H:i:s", strtotime("30 days ago")) . "'";

                # We record where we got up to using arrival.  We don't use msgid because the arrival gets reset when
                # we repost, but the msgid remains the same, and we want to send out messages which have been reposted
                # here.
                #
                # arrival is a high-precision timestamp, so it's effectively unique per message.
                $msgdtq = $track['msgdate'] ? " AND arrival > '{$track['msgdate']}' " : '';

                # If we're forcing, change the query so that we get a message to send.
                $limq = $uidforce ? " LIMIT 20 " : '';
                $ord = $uidforce ? " DESC " : " ASC ";
                $oldest = $uidforce ? '' : $oldest;
                $msgdtq = $uidforce ? '' : $msgdtq;

                $sql = "SELECT msgid, arrival, autoreposts FROM messages_groups WHERE groupid = ? AND collection = ? AND deleted = 0 $oldest $msgdtq ORDER BY arrival $ord $limq;";
                $messages = $this->dbhr->preQuery($sql, [
                    $groupid,
                    MessageCollection::APPROVED,
                ]);

                $subjects = [];
                $available = [];
                $unavailable = [];
                $maxmsg = 0;
                $maxdate = NULL;

                foreach ($messages as $message) {
                    $maxmsg = max($message['msgid'], $maxmsg);

                    # Because we order by arrival, this will end up being the most recent message, i.e. max(arrival).
                    $maxdate = $message['arrival'];

                    $m = new Message($this->dbhr, $this->dbhm, $message['msgid']);
                    $subject = $m->getSubject();
                    $availablenow = $m->getPrivate('availablenow');

                    if ($availablenow > 1) {
                        # Include this in the subject line.
                        $subject .= " [$availablenow available]";
                    }

                    $subjects[$message['msgid']] = $subject;

                    $atts = $m->getPublic(FALSE, TRUE, TRUE);
                    $atts['autoreposts'] = $message['autoreposts'];
                    $atts['subject'] = $subject;

                    $atts['firstposted'] = NULL;

                    if ($message['arrival'] != $atts['date']) {
                        # This message has been reposted.
                        $datetime = new \DateTime('@' . strtotime($atts['date']), $tz1);
                        $datetime->setTimezone($tz2);
                        $atts['firstposted'] = $datetime->format('D, jS F g:ia');
                    }

                    # Strip out the clutter associated with various ways of posting.
                    $atts['textbody'] = $m->stripGumf();

                    if ($atts['type'] == Message::TYPE_OFFER || $atts['type'] == Message::TYPE_WANTED) {
                        if (count($atts['outcomes']) == 0) {
                            $available[] = $atts;
                        } else {
                            $unavailable[] = $atts;
                        }
                    }
                }

                # Build the array of message(s) to send.  If we are sending immediately this may have multiple,
                # otherwise it'll just be one.
                #
                # We expand twig templates at this stage for fields which related to the message but not the
                # recipient.  Per-recipient fields are expanded using the Swift decorator later on, so for
                # those we expand them to a Swift variable.  That's a bit confusing but it means that
                # we don't expand the message variables more often than we need to, for performance reasons.
                $tosend = [];

                if ($frequency == Digest::IMMEDIATE) {
                    foreach ($available as $msg) {
                        # For immediate messages, which we send out as they arrive, we can set them to reply to
                        # the original sender.  We include only the text body of the message, because we
                        # wrap it up inside our own HTML.
                        #
                        # Anything that is per-group is passed in as a parameter here.  Anything that is or might
                        # become per-user is in the template as a {{...}} substitution.
                        $replyto = "replyto-{$msg['id']}-{{replyto}}@" . USER_DOMAIN;

                        $datetime = new \DateTime('@' . strtotime($msg['arrival']), $tz1);
                        $datetime->setTimezone($tz2);

                        try {
                            $html = $twig->render('digest/single.html', [
                                # Per-message fields for expansion now.
                                'fromname' => $msg['fromname'],
                                'subject' => $msg['subject'],
                                'textbody' => $msg['textbody'],
                                'image' => count($msg['attachments']) > 0 ? $msg['attachments'][0]['paththumb'] : NULL,
                                'groupname' => $gatts['namedisplay'],
                                'replyweb' => "https://" . USER_SITE . "/message/{$msg['id']}",
                                'replyemail' => "mailto:$replyto?subject=" . rawurlencode("Re: " . $msg['subject']),
                                'date' => $datetime->format('D, jS F g:ia'),
                                'autoreposts' => $msg['autoreposts'],
                                'sponsors' => $sponsors,

                                # Per-recipient fields for later Swift expansion
                                'settings' => '{{settings}}',
                                'unsubscribe' => '{{unsubscribe}}',
                                'email' => '{{email}}',
                                'frequency' => '{{frequency}}',
                                'noemail' => '{{noemail}}',
                                'visit' => '{{visit}}',
                                'jobads' => '{{jobads}}',
                                'joblocation' => '{{joblocation}}'
                            ]);

                            $tosend[] = [
                                'subject' => '[' . $gatts['namedisplay'] . "] {$msg['subject']}",
                                'from' => $replyto,
                                'fromname' => $msg['fromname'],
                                'replyto' => $replyto,
                                'replytoname' => $msg['fromname'],
                                'html' => $html,
                                'text' => $msg['textbody']
                            ];
                        } catch (\Exception $e) {
                            error_log("Message prepare failed with " . $e->getMessage());
                        }
                    }
                } else if (count($available) + count($unavailable) > 0) {
                    # Build up the HTML for the message(s) in it.  We add a teaser of items to make it more
                    # interesting.
                    $textsumm = "Here are new posts or reposts since we last mailed you.\r\n\r\n";
                    $availablesumm = '';
                    $count = count($available) > 0 ? count($available) : 1;
                    $subject = "[{$gatts['namedisplay']}] What's New ($count message" .
                        ($count == 1 ? ')' : 's)');
                    $subjinfo = '';
                    $twigmsgsavail = [];
                    $twigmsgsunavail = [];

                    # Text TOC
                    foreach ($available as $msg) {
                        $textsumm .= $msg['subject'] . " at https://" . USER_SITE . "/message/{$msg['id']}\r\n\r\n";
                    }

                    $textsumm .= "----------------\r\n\r\n";

                    # We want the reposts to appear at the bottom.
                    uasort($available, function($a, $b) {
                        if ($a['autoreposts'] == $b['autoreposts']) {
                            return(0);
                        } else if (!$a['autoreposts'] && $b['autoreposts']) {
                            return(-1);
                        } else {
                            return(1);
                        }
                    });

                    foreach ($available as $msg) {
                        $replyto = "replyto-{$msg['id']}-{{replyto}}@" . USER_DOMAIN;

                        $textsumm .= $msg['subject'] . " at \r\nhttps://" . USER_SITE . "/message/{$msg['id']}\r\n\r\n";
                        $textsumm .= $msg['textbody'] . "\r\n";
                        $textsumm .= "----------------\r\n\r\n";

                        $availablesumm .= $msg['subject'] . '<br />';

                        $twigmsgsavail[] = [
                            'id' => $msg['id'],
                            'subject' => $msg['subject'],
                            'textbody' => $msg['textbody'],
                            'fromname' => $msg['fromname'],
                            'image' => count($msg['attachments']) > 0 ? $msg['attachments'][0]['paththumb'] : NULL,
                            'replyweb' => "https://" . USER_SITE . "/message/{$msg['id']}",
                            'replyemail' => "mailto:$replyto?subject=" . rawurlencode("Re: " . $msg['subject']),
                            'autoreposts' => $msg['autoreposts'],
                            'firstposted' => $msg['firstposted'],
                            'date' => date("D, jS F g:ia", strtotime($msg['arrival'])),
                        ];

                        if (preg_match("/(.+)\:(.+)\((.+)\)/", $msg['subject'], $matches)) {
                            $item = trim($matches[2]);

                            if (strlen($item) < 25 && strlen($subjinfo) < 50) {
                                $subjinfo = $subjinfo == '' ? $item : "$subjinfo, $item";
                            }
                        }
                    }

                    $textsumm .= "\r\n\r\nThese posts are new since your last mail but have already been completed. If you missed something, try changing how frequently we send you email in Settings.\r\n\r\n";

                    foreach ($unavailable as $msg) {
                        $textsumm .= $msg['subject'] . " at \r\nhttps://" . USER_SITE . "/message/{$msg['id']}\r\n\r\n";
                        $availablesumm .= $msg['subject'] . '<br />';

                        $twigmsgsunavail[] = [
                            'id' => $msg['id'],
                            'subject' => $msg['subject'],
                            'textbody' => $msg['textbody'],
                            'fromname' => $msg['fromname'],
                            'image' => count($msg['attachments']) > 0 ? $msg['attachments'][0]['paththumb'] : NULL,
                            'replyweb' => NULL,
                            'replyemail' => NULL,
                            'autoreposts' => $msg['autoreposts'],
                            'firstposted' => $msg['firstposted'],
                            'date' => date("D, jS F g:ia", strtotime($msg['arrival'])),
                        ];

                        $textsumm .= $msg['subject'] . " (post completed, no longer active)\r\n";
                    }

                    if ($subjinfo) {
                        $subject .= " - $subjinfo...";
                    }

                    try {
                        $html = $twig->render('digest/multiple.html', [
                            # Per-message fields for expansion now.
                            'groupname' => $gatts['namedisplay'],
                            'availablemessages'=> $twigmsgsavail,
                            'unavailablemessages'=> $twigmsgsunavail,
                            'previewtext' => $textsumm,

                            # Per-recipient fields for later Swift expansion
                            'settings' => '{{settings}}',
                            'unsubscribe' => '{{unsubscribe}}',
                            'email' => '{{email}}',
                            'frequency' => '{{frequency}}',
                            'noemail' => '{{noemail}}',
                            'visit' => '{{visit}}',
                            'jobads' => '{{jobads}}',
                            'sponsors' => $sponsors,
                            'joblocation' => '{{joblocation}}'
                        ]);
                    } catch (\Exception $e) {
                        error_log("Message prepare failed with " . $e->getMessage());
                    }

                    $tosend[] = [
                        'subject' => $subject,
                        'from' => $g->getAutoEmail(),
                        'fromname' => $gatts['namedisplay'],
                        'replyto' => $g->getModsEmail(),
                        'replytoname' => $gatts['namedisplay'],
                        'html' => $html,
                        'text' => $textsumm
                    ];
                }

                if (count($tosend) > 0) {
                    # Now find the users we want to send to on this group for this frequency.
                    $uidq = $uidforce ? " AND userid = $uidforce " : '';
                    $sql = "SELECT userid FROM memberships WHERE groupid = ? AND emailfrequency = ? $uidq ORDER BY userid ASC;";
                    $users = $this->dbhr->preQuery($sql,
                                                   [ $groupid, $frequency ]);

                    $replacements = [];
                    $emailToId = [];

                    foreach ($users as $user) {
                        $u = User::get($this->dbhr, $this->dbhm, $user['userid']);
                        if ($this->errorlog) { error_log("Consider user {$user['userid']}"); }

                        # We are only interested in sending digests to users for whom we have a preferred address -
                        # otherwise where would we send them?  And not to TN members because they are just discarded
                        # there, so we are just wasting effort.
                        $email = $u->getEmailPreferred();
                        if ($this->errorlog) { error_log("Preferred $email, send ours " . $u->sendOurMails($g)); }

                        if ($email && $email != MODERATOR_EMAIL && !$u->isTN() && $u->sendOurMails($g)) {
                            $t = $u->loginLink(USER_SITE, $u->getId(), '/', User::SRC_DIGEST);
                            $creds = substr($t, strpos($t, '?'));

                            # We build up an array of the substitutions we need.
                            $jobads = $u->getJobAds();

                            $replacements[$email] = [
                                '{{uid}}' => $u->getId(),
                                '{{toname}}' => $u->getName(),
                                '{{bounce}}' => $u->getBounce(),
                                '{{settings}}' => $u->loginLink(USER_SITE, $u->getId(), '/settings', User::SRC_DIGEST),
                                '{{unsubscribe}}' => $u->loginLink(USER_SITE, $u->getId(), '/unsubscribe', User::SRC_DIGEST),
                                '{{email}}' => $email,
                                '{{frequency}}' => $this->freqText[$frequency],
                                '{{noemail}}' => 'digestoff-' . $user['userid'] . "-$groupid@" . USER_DOMAIN,
                                '{{post}}' => $u->loginLink(USER_SITE, $u->getId(), '/', User::SRC_DIGEST),
                                '{{visit}}' => $u->loginLink(USER_SITE, $u->getId(), '/mygroups', User::SRC_DIGEST),
                                '{{creds}}' => $creds,
                                '{{replyto}}' => $u->getId(),
                                '{{jobads}}' => $jobads['jobs'],
                                '{{joblocation}}' => $jobads['location']
                            ];

                            $emailToId[$email] = $u->getId();
                        }
                    }

                    if (count($replacements) > 0) {
                        error_log("#$groupid {$gatts['nameshort']} " . count($tosend) . " messages max $maxmsg, $maxdate to " . count($replacements) . " users");
                        # Now send.  We use a failover transport so that if we fail to send, we'll queue it for later
                        # rather than lose it.
                        list ($transport, $mailer) = Mail::getMailer($host);

                        # We're decorating using the information we collected earlier.  However the decorator doesn't
                        # cope with sending to multiple recipients properly (headers just get decorated with the first
                        # recipient) so we create a message for each recipient.
                        $decorator = new \Swift_Plugins_DecoratorPlugin($replacements);
                        $mailer->registerPlugin($decorator);

                        # We don't want to send too many mails before we reconnect.  This plugin breaks it up.
                        $mailer->registerPlugin(new \Swift_Plugins_AntiFloodPlugin(900));

                        $_SERVER['SERVER_NAME'] = USER_DOMAIN;
                        foreach ($tosend as $msg) {
                            foreach ($replacements as $email => $rep) {
                                try {
                                    # We created some HTML with logs of message links of this format:
                                    #   "https://" . USER_SITE . "/message/$msgid"
                                    # Add login info to them.
                                    # TODO This is a bit ugly.  Now that we send a single message per recipient is it
                                    # worth the double-loop we have in this function?
                                    $html = preg_replace('/(https:\/\/' . USER_SITE . '\/message\/[0-9]*)/', '$1' . $rep['{{creds}}'], $msg['html']);

                                    # If the text bodypart is empty, then it is omitted.  For mail clients set to display
                                    # the text bodypart, they may then make an attempt to display the HTML bodypart as
                                    # text, which looks wrong.  So make sure it's not empty.
                                    $msg['text'] = $msg['text'] ? $msg['text'] : '.';

                                    $message = \Swift_Message::newInstance()
                                        ->setSubject($msg['subject'] . ' ' . User::encodeId($emailToId[$email]))
                                        ->setFrom([$msg['from'] => $msg['fromname']])
                                        ->setReturnPath($rep['{{bounce}}'])
                                        ->setReplyTo($msg['replyto'], $msg['replytoname'])
                                        ->setBody($msg['text']);

                                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                    # Outlook.
                                    $htmlPart = \Swift_MimePart::newInstance();
                                    $htmlPart->setCharset('utf-8');
                                    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                    $htmlPart->setContentType('text/html');
                                    $htmlPart->setBody($html);
                                    $message->attach($htmlPart);

                                    $headers = $message->getHeaders();
                                    $headers->addTextHeader('List-Unsubscribe', '<mailto:{{noemail}}>, <{{unsubscribe}}>');
                                    $message->setTo([ $email => $rep['{{toname}}'] ]);

                                    Mail::addHeaders($message,Mail::DIGEST, $rep['{{uid}}'], $frequency);

                                    #error_log("Send to $email");
                                    $this->sendOne($mailer, $message);
                                    $sent++;
                                } catch (\Exception $e) {
                                    error_log($email . " skipped with " . $e->getMessage());
                                }
                            }
                        }
                    }

                    if ($maxdate) {
                        # Record the message we got upto.
                        $sql = "UPDATE groups_digests SET msgid = ?, msgdate = ? WHERE groupid = ? AND frequency = ?;";
                        $this->dbhm->preExec($sql, [$maxmsg, $maxdate, $groupid, $frequency]);
                    }
                }

                $sql = "UPDATE groups_digests SET ended = NOW() WHERE groupid = ? AND frequency = ?;";
                $this->dbhm->preExec($sql, [$groupid, $frequency]);
            }
        }

        return($sent);
    }
}