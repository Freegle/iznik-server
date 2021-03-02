<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/relevant/wrapper.php');
require_once(IZNIK_BASE . '/mailtemplates/relevant/one.php');
require_once(IZNIK_BASE . '/mailtemplates/relevant/off.php');

# Find messages relevant to users which they might have missed, and mail them to them.
class Relevant {
    const MATCH_POST = 'Post';
    const MATCH_SEARCH = 'Search';
    const MATCH_VIEWED = 'Viewed';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public function off($uid) {
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        if ($u->getId() == $uid) {
            $u->setPrivate('relevantallowed', 0);

            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_RELEVANTOFF,
                'user' => $uid
            ]);

            $email = $u->getEmailPreferred();
            if ($email) {
                list ($transport, $mailer) = Mail::getMailer();
                $html = relevant_off(USER_SITE, USERLOGO);

                $message = \Swift_Message::newInstance()
                    ->setSubject("Email Change Confirmation")
                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                    ->setReturnPath($u->getBounce())
                    ->setTo([$email => $u->getName()])
                    ->setBody("Thanks - we've turned off the mails of posts you might be interested in.");

                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                # Outlook.
                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);

                Mail::addHeaders($message, Mail::RELEVANT_OFF, $u->getId());

                $this->sendOne($mailer, $message);
            }
        }
    }

    public function findRelevant($userid, $grouptype = Group::GROUP_FREEGLE, $earliest = NULL, $latest = "24 hours ago") {
        $interested = [];
        $terms = [];

        $earlyq = $earliest ? (" AND messages.arrival > '" . date("Y-m-d H:i:s", strtotime($earliest)) . "' ") : NULL;
        $lateq = $latest ? (" AND messages.arrival <= '" . date("Y-m-d H:i:s", strtotime($latest)) . "' ") : NULL;

        $u = User::get($this->dbhr, $this->dbhm, $userid);

        # Only send these mails if they are a still member of a group.
        if (count($u->getMemberships(FALSE, $grouptype)) > 0) {
            # We have two sources:
            # - Outstanding posts by the user, which might be either OFFERs or WANTEDs, where we want to look for the
            # - Recently viewed posts.
            #
            # Don't use searches - we don't know which way round they are, and if the search found anything useful
            # then it will be reflected better by what they clicked to view.

            # Anything longer ago probably isn't relevant.
            $start = date('Y-m-d', strtotime("30 days ago"));

            # First the messages.
            $sql = "SELECT DISTINCT messages.type, messages.subject, messages.arrival, messages.id FROM messages LEFT OUTER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND collection = 'Approved' INNER JOIN groups ON groups.id = messages_groups.groupid AND groups.type = ? AND groups.onhere = 1 WHERE messages_outcomes.msgid IS NULL AND fromuser = ? AND messages.type IN ('Offer', 'Wanted') AND messages.arrival >= ? AND messages_groups.deleted = 0 $earlyq $lateq;";
            $msgs = $this->dbhr->preQuery($sql, [ $grouptype, $userid, $start ] );
            #error_log("Look for posts from $userid since $start found " . count($msgs));
            foreach ($msgs as $msg) {
                # We only bother with messages with standard subject line formats.
                if (preg_match("/(.+)\:(.+)\((.+)\)/", $msg['subject'], $matches)) {
                    $item = trim($matches[2]);

                    if (!array_key_exists($item, $terms)) {
                        $terms[$item] = TRUE;
                        $interested[] = [
                            'type' => $msg['type'],
                            'item' => $item,
                            'reason' => [
                                'type' => Relevant::MATCH_POST,
                                'msgid' => $msg['id'],
                                'subject' => $msg['subject'],
                                'date' => Utils::ISODate($msg['arrival'])
                            ]
                        ];
                    }
                }
            }

            # Now the recent views of other people's messages.
            $sql = "SELECT messages.type, messages.id, messages.subject, messages_likes.timestamp FROM messages_likes 
    INNER JOIN messages ON messages.id = messages_likes.msgid 
    WHERE userid = ? AND timestamp >= ? AND messages_likes.type = ? AND messages.fromuser != ?;";
            $views = $this->dbhr->preQuery($sql, [
                $userid,
                $start,
                Message::LIKE_VIEW,
                $userid
            ]);

            foreach ($views as $view) {
                # We only bother with messages with standard subject line formats.
                if (preg_match("/(.+)\:(.+)\((.+)\)/", $view['subject'], $matches)) {
                    $item = trim($matches[2]);

                    if (!array_key_exists($item, $terms)) {
                        $terms[$item] = true;

                        $interested[] = [
                            'item' => $item,
                            'type' => $view['type'] == Message::TYPE_OFFER ? Message::TYPE_WANTED : Message::TYPE_OFFER,
                            'reason' => [
                                'type' => Relevant::MATCH_VIEWED,
                                'msgid' => $view['id'],
                                'term' => $item,
                                'date' => Utils::ISODate($view['timestamp'])
                            ]
                        ];
                    }
                }
            }
        }

        return($interested);
    }

    public function getMessages($userid, $interesteds, $earliest = NULL) {
        $ret = [];
        $ids = [];
        $earlyq = $earliest ? " AND messages.arrival > '$earliest'" : NULL;

        # We want to search in the groups near the last location we have for this user.
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $lastloc = $u->getPrivate('lastlocation');

        # We are interested in messages since the last check - or if this is the first, fairly recent ones.
        $start = $u->getPrivate('lastrelevantcheck');
        $start = $start ? strtotime($start) : strtotime("3 days ago");

        if ($lastloc) {
            $l = new Location($this->dbhr, $this->dbhm, $lastloc);
            $groups = $l->groupsNear(Location::QUITENEARBY);
            #error_log("Groups near $lastloc are " . var_export($groups, TRUE));
        } else {
            $groups = $u->getMembershipGroupIds();
        }

        # Only want groups where this function is allowed.
        $allowed = [];
        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group);
            if ($g->getSetting('relevant', 1) && $g->getPrivate('onhere')) {
                $allowed[] = $group;
            }
        }

        $groups = $allowed;

        if (count($groups) > 0) {
            foreach ($interesteds as $interested) {
                $s = new Search($this->dbhr, $this->dbhm, 'messages_index', 'msgid', 'arrival', 'words', 'groupid', $start);
                $ctx = NULL;

                # We want to search for exact matches only, as some of the others will look silly.
                $res = $s->search($interested['item'], $ctx, 10, NULL, $groups, TRUE);
                #error_log("Search for {$interested['item']} because {$interested['reason']['type']} returned " . var_export($res, TRUE));

                foreach ($res as $r) {
                    if (!in_array($r['id'], $ids)) {
                        # We have a message - see if it's the type we want.
                        $m = new Message($this->dbhr, $this->dbhm, $r['id']);
                        $type = $m->getType();
                        #error_log("Check nessage {$r['id']} type $type from " . $m->getFromuser() . " vs $userid");

                        if ($m->getFromuser() && $m->getFromuser() != $userid &&
                            (($interested['type'] == Message::TYPE_OFFER && $type == Message::TYPE_WANTED) ||
                                ($interested['type'] == Message::TYPE_WANTED && $type == Message::TYPE_OFFER)) &&
                            (!$earliest || strtotime($earliest) < strtotime($m->getPrivate('arrival')))
                        ) {
                            #error_log("Found {$r['id']} " . $m->getSubject() . " from " . var_export($r, TRUE));
                            $ret[] = [
                                'id' => $r['id'],
                                'term' => $interested['item'],
                                'matchedon' => $r['matchedon']['word'],
                                'reason' => $interested['reason']
                            ];

                            $ids[] = $r['id'];
                        }
                    }
                }
            }
        }

        usort($ret, function($a, $b) {
            return($b['id'] - $a['id']);
        });

        return(array_slice($ret, 0, 10));
    }

    public function recordCheck($userid) {
        $this->dbhm->preExec("UPDATE users SET lastrelevantcheck = NOW() WHERE id = ?;", [ $userid ]);
        User::clearCache($userid);
    }

    # Split out for UT to override
    public function sendOne($mailer, $message) {
        $mailer->send($message);
    }

    public function sendMessages($userid = NULL, $earliest = NULL, $latest = "24 hours ago") {
        list ($transport, $mailer) = Mail::getMailer();

        $count = 0;

        $sql = $userid ? "SELECT id FROM users WHERE id = $userid AND relevantallowed = 1;" : "SELECT id FROM users WHERE relevantallowed = 1;";
        $users = $this->dbhr->preQuery($sql);

        foreach ($users as $user) {
            $u = User::get($this->dbhr, $this->dbhm, $user['id']);

            # Only want to send to people who have used FD.
            #error_log("Check send our emails");
            if ($u->getOurEmail() && $u->sendOurMails()) {
                $ints = $this->findRelevant($user['id'], Group::GROUP_FREEGLE, $earliest, $latest);
                $msgs = $this->getMessages($user['id'], $ints);
                #error_log("Number of messages " . count($msgs) . " from " . var_export($ints, TRUE) . " and " . var_export($msgs, TRUE));;

                if (count($msgs) > 0) {
                    $noemail = 'relevantoff-' . $user['id'] . "@" . USER_DOMAIN;
                    $textbody = "Based on what you've offered or searched for, we thought you might be interested in these recent messages.\r\nIf you don't want to get these suggestions, mail $noemail.";
                    $offers = [];
                    $wanteds = [];
                    $hoffers = [];
                    $hwanteds = [];

                    foreach ($msgs as $msg) {
                        $m = new Message($this->dbhr, $this->dbhm, $msg['id']);

                        # We need the approved ID on Yahoo for migration links.
                        $href = $u->loginLink(USER_SITE, $u->getId(), "/message/{$msg['id']}", User::SRC_RELEVANT);
                        $subject = $m->getSubject();
                        $subject = preg_replace('/\[.*?\]\s*/', '', $subject);

                        if ($m->getType() == Message::TYPE_OFFER) {
                            $offers[] = "$subject - see $href\r\n";
                            $hoffers[] = relevant_one($subject, $href, $msg['matchedon'], $msg['reason']);
                        } else {
                            $wanteds[] = "$subject - see $href\r\n";
                            $hwanteds[] = relevant_one($subject, $href, $msg['matchedon'], $msg['reason']);
                        }
                    }

                    $textbody .= count($offers) > 0 ? ("\r\nThings people are giving away which you might want:\r\n\r\n" . implode('', $offers)) : '';
                    $textbody .= count($wanteds) > 0 ? ("\r\nThings people are looking for which you might have:\r\n\r\n" . implode('', $wanteds)) : '';

                    $htmloffers = count($offers) > 0 ? ("<p>Things people are giving away which you might want:</p>" . implode('', $hoffers)) : '';
                    $htmlwanteds = count($wanteds) > 0 ? ("<p>Things people are looking for which you might have:</p>" . implode('', $hwanteds)) : '';

                    $email = $u->getEmailPreferred();
                    #error_log("Preferred email $email");

                    if ($email) {
                        $subj = "Any of these take your fancy?";
                        $post = $u->loginLink(USER_SITE, $u->getId(), "/", User::SRC_RELEVANT);
                        $unsubscribe = $u->loginLink(USER_SITE, $u->getId(), "/unsubscribe", User::SRC_RELEVANT);
                        $visit = $u->loginLink(USER_SITE, $u->getId(), "/mygroups", User::SRC_RELEVANT);

                        $html = relevant_wrapper(USER_SITE, USERLOGO, $subj, $htmloffers, $htmlwanteds, $email, $noemail, $post, $visit, $unsubscribe);

                        try {
                            $message = \Swift_Message::newInstance()
                                ->setSubject($subj)
                                ->setFrom([NOREPLY_ADDR => SITE_NAME ])
                                ->setReturnPath($u->getBounce())
                                ->setTo([ $email => $u->getName() ])
                                ->setBody($textbody);

                            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                            # Outlook.
                            $htmlPart = \Swift_MimePart::newInstance();
                            $htmlPart->setCharset('utf-8');
                            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                            $htmlPart->setContentType('text/html');
                            $htmlPart->setBody($html);
                            $message->attach($htmlPart);

                            Mail::addHeaders($message, Mail::RELEVANT, $u->getId());

                            $this->sendOne($mailer, $message);
                            #error_log("Sent to $email");
                            $count++;
                        } catch (\Exception $e) {
                            error_log("Send to $email failed with " . $e->getMessage());
                        }
                    }
                }
            }
        }

        return($count);
    }
}