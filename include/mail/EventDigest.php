<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/misc/Mail.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/group/CommunityEvent.php');
require_once(IZNIK_BASE . '/mailtemplates/digest/eventsoff.php');

class EventDigest
{
    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;

    private $errorlog;

    function __construct($dbhr, $dbhm, $errorlog = FALSE)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($this->dbhr, $this->dbhm);
        $this->errorlog = $errorlog;
    }

    # Split out for UT to override
    public function sendOne($mailer, $message) {
        $mailer->send($message);
    }

    public function off($uid, $groupid) {
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        if ($u->isApprovedMember($groupid)) {
            $u->setMembershipAtt($groupid, 'eventsallowed', 0);
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);

            # We can receive messages for emails from the old system where the group id is no longer valid.
            if ($g->getId() == $groupid) {
                $groupname = $g->getPublic()['namedisplay'];

                $this->log->log([
                    'type' => Log::TYPE_USER,
                    'subtype' => Log::SUBTYPE_EVENTSOFF,
                    'user' => $uid,
                    'groupid' => $groupid
                ]);

                $email = $u->getEmailPreferred();
                if ($email) {
                    list ($transport, $mailer) = getMailer();
                    $html = events_off(USER_SITE, USERLOGO, $groupname);

                    $message = Swift_Message::newInstance()
                        ->setSubject("Email Change Confirmation")
                        ->setFrom([NOREPLY_ADDR => SITE_NAME])
                        ->setReturnPath($u->getBounce())
                        ->setTo([ $email => $u->getName() ])
                        ->setBody("We've turned your community event emails off on $groupname.");

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($html);
                    $message->attach($htmlPart);

                    Mail::addHeaders($message, Mail::EVENTS_OFF, $u->getId());

                    $this->sendOne($mailer, $message);
                }
            }
        }
    }

    public function send($groupid, $ccto = NULL) {
        $loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new Twig_Environment($loader);

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $gatts = $g->getPublic();
        $sent = 0;

        if ($this->errorlog) { error_log("#$groupid " . $g->getPrivate('nameshort')); }

        # We want to send all events which start within the next month for this group.
        $sql = "SELECT DISTINCT id FROM (SELECT DISTINCT communityevents.id, title, start FROM communityevents INNER JOIN communityevents_groups ON communityevents_groups.eventid = communityevents.id AND groupid = ? INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id WHERE start >= NOW() AND DATEDIFF(NOW(), start) <= 30 AND pending = 0 AND deleted = 0 ORDER BY communityevents_dates.start ASC) t;";
        #error_log("Look for groups to process $sql, $groupid");
        $events = $this->dbhr->preQuery($sql, [ $groupid ]);

        if ($this->errorlog) { error_log("Consider " . count($events) . " events"); }

        $textsumm = '';

        $tz1 = new DateTimeZone('UTC');
        $tz2 = new DateTimeZone('Europe/London');

        $twigevents = [];

        if (count($events) > 0) {
            foreach ($events as $event) {
                if ($this->errorlog) { error_log("Start group $groupid"); }

                $e = new CommunityEvent($this->dbhr, $this->dbhm, $event['id']);
                $atts = $e->getPublic();

                foreach ($atts['dates'] as $date) {
                    if (strtotime($date['end']) >= time())  {
                        # Get a string representation of the date in UK time.
                        $datetime = new DateTime($date['start'], $tz1);
                        $datetime->setTimezone($tz2);
                        $start = $datetime->format('D, jS F g:ia');

                        $datetime = new DateTime($date['end'], $tz1);
                        $datetime->setTimezone($tz2);
                        $end = $datetime->format('D, jS F g:ia');

                        $textsumm .= $atts['title'] . " starts $start at " . $atts['location'] . " - for details see https://" . USER_SITE . "//communityevent/{$atts['id']}&src=eventdigest\r\n\r\n";
                        $atts['start'] = $start;
                        $atts['end'] = $end;
                        $atts['otherdates'] = NULL;

                        if (count($atts['dates']) > 1) {
                            foreach ($atts['dates'] as $date2) {
                                if (strtotime($date2['end']) >= time() && $date2['end'] != $date['end']) {
                                    $datetime = new DateTime($date2['start'], $tz1);
                                    $datetime->setTimezone($tz2);
                                    $start2 = $datetime->format('D, jS F g:ia');

                                    $datetime = new DateTime($date2['end'], $tz1);
                                    $datetime->setTimezone($tz2);
                                    $end2 = $datetime->format('D, jS F g:ia');
                                    $atts['otherdates'] = $atts['otherdates'] ? ($atts['otherdates'] . ", $start2-$end2") : "$start2-$end2";
                                }
                            }
                        }

                        $twigevents[] = $atts;

                        # Only send the first occurrence that happens in this period.
                        break;
                    }
                }
            }

            $html = $twig->render('digest/events.html', [
                # Per-message fields for expansion now.
                'events' => $twigevents,
                'groupname' => $gatts['namedisplay'],

                # Per-recipient fields for later Swift expansion
                'settings' => '{{settings}}',
                'unsubscribe' => '{{unsubscribe}}',
                'email' => '{{email}}',
                'noemail' => '{{noemail}}',
                'visit' => '{{visit}}'
            ]);

            $tosend = [
                'subject' => '[' . $gatts['namedisplay'] . "] Community Event Roundup",
                'from' => $g->getAutoEmail(),
                'fromname' => $gatts['namedisplay'],
                'replyto' => $g->getModsEmail(),
                'replytoname' => $gatts['namedisplay'],
                'html' => $html,
                'text' => $textsumm
            ];

            # Now find the users we want to send to on this group for this frequency.  We build up an array of
            # the substitutions we need.
            # TODO This isn't that well indexed in the table.
            $replacements = [];

            $sql = "SELECT userid FROM memberships WHERE groupid = ? AND eventsallowed = 1 ORDER BY userid ASC;";
            $users = $this->dbhr->preQuery($sql, [ $groupid, ]);

            if ($this->errorlog) { error_log("Consider " . count($users) . " users "); }
            foreach ($users as $user) {
                $u = User::get($this->dbhr, $this->dbhm, $user['userid']);
                if ($this->errorlog) {
                    error_log("Consider user {$user['userid']}");
                }

                # We are only interested in sending events to users for whom we have a preferred address -
                # otherwise where would we send them?
                $email = $u->getEmailPreferred();
                #$email = 'activate@liveintent.com';

                $jobads = $u->getJobAds();

                if ($this->errorlog) { error_log("Preferred $email, send " . $u->sendOurMails($g)); }

                if ($email && $u->sendOurMails($g)) {
                    if ($this->errorlog) { error_log("Send to them"); }

                    $replacements[$email] = [
                        '{{id}}' => $u->getId(),
                        '{{toname}}' => $u->getName(),
                        '{{settings}}' => $u->loginLink(USER_SITE, $u->getId(), '/settings', User::SRC_DIGEST),
                        '{{unsubscribe}}' => $u->loginLink(USER_SITE, $u->getId(), '/unsubscribe', User::SRC_EVENT_DIGEST),
                        '{{email}}' => $email,
                        '{{noemail}}' => 'eventsoff-' . $user['userid'] . "-$groupid@" . USER_DOMAIN,
                        '{{post}}' => "https://" . USER_SITE . "/communityevents",
                        '{{visit}}' => "https://" . USER_SITE . "/mygroups",
                        '{{jobads}}' => $jobads['jobs'] && count($jobads['jobs']) ? implode('<br />', $jobads['jobs']) : NULL,
                        '{{joblocation}}' => $jobads['location']
                    ];
                }
            }

            if (count($replacements) > 0) {
                error_log("#$groupid {$gatts['nameshort']} to " . count($replacements) . " users");

                # Now send.  We use a failover transport so that if we fail to send, we'll queue it for later
                # rather than lose it.
                list ($transport, $mailer) = getMailer();

                # We're decorating using the information we collected earlier.  However the decorator doesn't
                # cope with sending to multiple recipients properly (headers just get decorated with the first
                # recipient) so we create a message for each recipient.
                $decorator = new Swift_Plugins_DecoratorPlugin($replacements);
                $mailer->registerPlugin($decorator);

                # We don't want to send too many mails before we reconnect.  This plugin breaks it up.
                $mailer->registerPlugin(new Swift_Plugins_AntiFloodPlugin(900));

                $_SERVER['SERVER_NAME'] = USER_DOMAIN;

                foreach ($replacements as $email => $rep) {
                    $message = Swift_Message::newInstance()
                        ->setSubject($tosend['subject'] . ' ' . User::encodeId($rep['{{id}}']))
                        ->setFrom([$tosend['from'] => $tosend['fromname']])
                        ->setReturnPath($u->getBounce())
                        ->setReplyTo($tosend['replyto'], $tosend['replytoname'])
                        ->setBody($tosend['text']);

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($tosend['html']);
                    $message->attach($htmlPart);

                    Mail::addHeaders($message, Mail::EVENTS, $u->getId());

                    $headers = $message->getHeaders();
                    $headers->addTextHeader('List-Unsubscribe', '<mailto:{{noemail}}>, <{{unsubscribe}}>');

                    try {
                        $message->setTo([ $email => $rep['{{toname}}'] ]);
                        #error_log("...$email");
                        $this->sendOne($mailer, $message);
                        $sent++;
                    } catch (Exception $e) {
                        error_log($email . " skipped with " . $e->getMessage());
                    }
                }
            }
        }

        $this->dbhm->preExec("UPDATE groups SET lasteventsroundup = NOW() WHERE id = ?;", [ $groupid ]);
        Group::clearCache($groupid);

        return($sent);
    }
}