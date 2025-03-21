<?php
namespace Freegle\Iznik;

class Story extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'date', 'public', 'headline', 'story', 'reviewed', 'newsletterreviewed', 'newsletter');
    var $settableatts = array('public', 'headline', 'story', 'reviewed', 'newsletterreviewed', 'newsletter');

    const ASK_OUTCOME_THRESHOLD = 3;
    const ASK_OFFER_THRESHOLD = 5;

    const LIKE = 'Like';
    const UNLIKE = 'Unlike';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'users_stories', 'story', $this->publicatts);
    }

    public function setAttributes($settings) {
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);

        foreach ($this->settableatts as $att) {
            if (array_key_exists($att, $settings)) {
                $this->setPrivate($att, $settings[$att]);

                if ($myid) {
                    if ($att == 'reviewed') {
                        $this->setPrivate('reviewedby', $myid);
                    } else if ($att == 'newsletterreviewed') {
                        $this->setPrivate('newsletterreviewedby', $myid);
                    }
                }
            }
        }
    }

    public function create($userid, $public, $headline, $story, $photo = NULL) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO users_stories (public, userid, headline, story) VALUES (?,?,?,?);", [
            $public,
            $userid,
            $headline,
            $story
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            if ($id) {
                $this->fetch($this->dbhm, $this->dbhm, $id, 'users_stories', 'story', $this->publicatts);
            }

            if ($photo) {
                $this->setPhoto($photo);
            }
        }

        return($id);
    }

    public function setPhoto($photoid) {
        $this->dbhm->preExec("UPDATE users_stories_images SET storyid = ? WHERE id = ?;", [ $this->id, $photoid ]);
    }

    public function getPublic() {
        $ret = parent::getPublic();

        $ret['date'] = Utils::ISODate($ret['date']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $u = User::get($this->dbhr, $this->dbhm, $this->story['userid']);

        if ($me && $me->isModerator() && $this->story['userid']) {
            if ($me->hasPermission(User::PERM_NEWSLETTER) || $me->moderatorForUser($this->story['userid'], TRUE)) {
                $ret['user'] = $u->getPublic();
                $ret['user']['email'] = $u->getEmailPreferred();
            }
        }

        $membs = $u->getMemberships();
        $groupname = NULL;
        $groupid = NULL;

        if (count($membs) > 0) {
            shuffle($membs);
            foreach ($membs as $memb) {
                if ($memb['type'] == Group::GROUP_FREEGLE && $memb['onmap']) {
                    $groupname = $memb['namedisplay'];
                    $groupid = $memb['id'];
                }
            }
        }

        $ret['groupname'] = $groupname;
        $ret['groupid'] = $groupid;

        $likes = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM users_stories_likes WHERE storyid = ?;", [
            $this->id
        ]);

        $ret['likes'] = $likes[0]['count'];
        $ret['liked'] = FALSE;
        if ($me) {
            $likes = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM users_stories_likes WHERE storyid = ? AND userid = ?;", [
                $this->id,
                $me->getId()
            ]);
            $ret['liked'] = $likes[0]['count'] > 0;
        }

        $photos = $this->dbhr->preQuery("SELECT id FROM users_stories_images WHERE storyid = ?;", [ $this->id ]);
        foreach ($photos as $photo) {
            $a = new Attachment($this->dbhr, $this->dbhm, $photo['id'], Attachment::TYPE_STORY);

            $ret['photo'] = [
                'id' => $photo['id'],
                'path' => $a->getPath(FALSE),
                'paththumb' => $a->getPath(TRUE)
            ];
        }

        $ret['url'] = 'https://' . USER_SITE . '/story/' . $ret['id'];

        return($ret);
    }

    public function canSee() {
        # Can see our own, or all if we have permissions, or if it's public
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);
        return($this->story['public'] || $this->story['userid'] == $myid || ($me && $me->isAdminOrSupport()));
    }

    public function canMod() {
        $ret = FALSE;

        if ($this->story) {
            # We can modify if it's ours, we are an admin, or a mod on a group that the author is a member of.
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = Session::whoAmId($this->dbhr, $this->dbhm);
            $author = User::get($this->dbhr, $this->dbhm, $this->story['userid']);
            $authormembs = $author->getMemberships(FALSE);
            $ret = ($this->story['userid'] == $myid) || ($me && $me->isAdminOrSupport());

            if ($myid) {
                $membs = $me->getMemberships(TRUE);
                foreach ($membs as $memb) {
                    foreach ($authormembs as $authormemb) {
                        if ($authormemb['id'] == $memb['id']) {
                            $ret = TRUE;
                        }
                    }
                }
            }
        }

        return($ret);
    }

    public function getForReview($groupids, $newsletter) {
        $mysqltime = date("Y-m-d", strtotime("31 days ago"));
        $sql = $newsletter ? ("SELECT DISTINCT users_stories.id FROM users_stories INNER JOIN memberships ON memberships.userid = users_stories.userid WHERE reviewed = 1 AND public = 1 AND newsletterreviewed = 0 ORDER BY date DESC") :
            ("SELECT DISTINCT users_stories.id FROM users_stories INNER JOIN memberships ON memberships.userid = users_stories.userid WHERE memberships.groupid IN (" . implode(',', $groupids) . ") AND users_stories.date > '$mysqltime' AND reviewed = 0 ORDER BY date DESC");
        $ids = $this->dbhr->preQuery($sql);
        $ret = [];

        foreach ($ids as $id) {
            $s = new Story($this->dbhr, $this->dbhm, $id['id']);
            $ret[] = $s->getPublic();
        }

        return($ret);
    }

    public function getReviewCount($newsletter, $me = NULL, $mygroups = NULL) {
        $me = $me ? $me : Session::whoAmI($this->dbhr, $this->dbhm);
        $mysqltime = date("Y-m-d", strtotime("31 days ago"));

        if ($newsletter) {
            $sql = "SELECT COUNT(DISTINCT(users_stories.id)) AS count FROM users_stories INNER JOIN memberships ON memberships.userid = users_stories.userid WHERE reviewed = 1 AND public = 1 AND newsletterreviewed = 0 ORDER BY date DESC";
        } else {
            if (!$mygroups) {
                $mygroups = $me->getMemberships(TRUE, Group::GROUP_FREEGLE, FALSE, FALSE, NULL, FALSE);
            }

            $groupids = [0];
            foreach ($mygroups as $mygroup) {
                # This group might have turned stories off.  Bypass the Group object in the interest of performance
                # for people on many groups.
                if (($mygroup['role'] == User::ROLE_MODERATOR || $mygroup['role'] == User::ROLE_OWNER) && $me->activeModForGroup($mygroup['id'])) {
                    if (!array_key_exists('stories', $mygroup['settings']) || $mygroup['settings']['stories']) {
                        $groupids[] = $mygroup['id'];
                    }
                }
            }

            $sql = "SELECT COUNT(DISTINCT users_stories.id) AS count FROM users_stories INNER JOIN memberships ON memberships.userid = users_stories.userid WHERE memberships.groupid IN (" . implode(',', $groupids) . ")  AND users_stories.date > '$mysqltime' AND reviewed = 0 ORDER BY date DESC;";
        }

        $ids = $this->dbhr->preQuery($sql);
        return($ids[0]['count']);
    }

    public function getStories($groupid, $authorityid, $story, $limit = 20, $reviewnewsletter = FALSE) {
        $limit = intval($limit);
        if ($reviewnewsletter) {
            # This is for mods reviewing stories for inclusion in the newsletter
            $last = $this->dbhr->preQuery("SELECT MAX(created) AS max FROM newsletters WHERE type = 'Stories';");
            $since = $last[0]['max'];
            $dateq = $since ? "AND date >= '$since'": '';
            $sql = "SELECT DISTINCT users_stories.id FROM users_stories WHERE newsletter = 1 AND public = 1 AND newsletterreviewed = 1 AND mailedtomembers = 0 $dateq ORDER BY RAND();";
            $ids = $this->dbhr->preQuery($sql);
        } else {
            if ($groupid) {
                # Get stories where the user is a member of this group.  May cause same story to be visible across multiple groups.
                $sql = "SELECT DISTINCT users_stories.id FROM users_stories INNER JOIN memberships ON memberships.userid = users_stories.userid WHERE memberships.groupid = $groupid AND reviewed = 1 AND public = 1 AND users_stories.userid IS NOT NULL ORDER BY date DESC LIMIT $limit;";
                $ids = $this->dbhr->preQuery($sql);
            } else if ($authorityid) {
                # Get stories where the user has a location within the authority.  May omit users where we don't know their location.  Bit slow.
                $a = new Authority($this->dbhr, $this->dbhm, $authorityid);
                $stories = $this->dbhr->preQuery("SELECT id, userid FROM users_stories WHERE reviewed = 1 AND public = 1 AND userid IS NOT NULL ORDER BY date DESC;");
                $ids = [];

                foreach ($stories as $story) {
                    $u = User::get($this->dbhr, $this->dbhm, $story['userid']);
                    list ($lat, $lng, $loc) = $u->getLatLng();

                    if (($lat || $lng) && $a->contains($lat, $lng)) {
                        $ids[] = $story;
                    }

                    if (count($ids) >= $limit) {
                        break;
                    }
                }

            } else {
                $sql = "SELECT DISTINCT users_stories.id FROM users_stories WHERE reviewed = 1 AND public = 1 AND userid IS NOT NULL ORDER BY date DESC LIMIT $limit;";
                $ids = $this->dbhr->preQuery($sql);
            }
        }

        $ret = [];

        foreach ($ids as $id) {
            $s = new Story($this->dbhr, $this->dbhm, $id['id']);
            $thisone = $s->getPublic();
            if (!$story) {
                unset($thisone['story']);
            }

            $ret[] = $thisone;
        }

        return($ret);
    }

    public function askForStories($earliest, $userid = NULL, $outcomethreshold = Story::ASK_OUTCOME_THRESHOLD, $offerthreshold = Story::ASK_OFFER_THRESHOLD, $groupid = NULL, $force = FALSE) {
        $userq = $userid ? " AND fromuser = $userid " : "";
        $groupq = $groupid ? " INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND messages_groups.groupid = $groupid " : "";
        $forceq = $force ? '' : " AND users_stories_requested.date IS NULL ";
        $sql = "SELECT DISTINCT fromuser FROM messages $groupq 
            LEFT OUTER JOIN users_stories_requested ON users_stories_requested.userid = messages.fromuser 
            WHERE messages.arrival > ? AND fromuser IS NOT NULL $forceq $userq;";
        $users = $this->dbhr->preQuery($sql, [ $earliest ]);
        $asked = 0;

        error_log("Found " . count($users) . " possible users");

        foreach ($users as $user) {
            $outcomes = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_by WHERE userid = ?;", [ $user['fromuser'] ]);
            $outcomecount = $outcomes[0]['count'];
            $offers = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM messages WHERE fromuser = ? AND type = 'Offer';", [ $user['fromuser'] ]);
            $offercount = $offers[0]['count'];
            #error_log("Userid {$user['fromuser']} outcome count $outcomecount offer count $offercount");

            if ($outcomecount > $outcomethreshold || $offercount > $offerthreshold) {
                # Record that we've thought about asking.  This means we won't consider them repeatedly.
                $this->dbhm->preExec("INSERT INTO users_stories_requested (userid) VALUES (?);", [ $user['fromuser'] ]);

                # We only want to ask if they are a member of a group which has stories enabled.
                $u = new User($this->dbhr, $this->dbhm, $user['fromuser']);
                $membs = $u->getMemberships();
                $ask = FALSE;
                foreach ($membs as $memb) {
                    $g = Group::get($this->dbhr, $this->dbhm, $memb['id']);
                    $stories = $g->getSetting('stories', 1);
                    #error_log("Consider send for " . $u->getEmailPreferred() . " stories $stories, groupid $groupid vs {$memb['id']}");
                    if ($stories && (!$groupid || $groupid == $memb['id'])) {
                        $ask = TRUE;
                    }
                }

                if ($ask) {
                    $asked++;
                    $url = $u->loginLink(USER_SITE, $user['fromuser'], '/stories');

                    $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/stories');
                    $twig = new \Twig_Environment($loader);

                    $html = $twig->render('ask.html', [
                        'name' => $u->getName(),
                        'email' => $u->getEmailPreferred(),
                        'unsubscribe' => $u->loginLink(USER_SITE, $u->getId(), "/unsubscribe", NULL)
                    ]);

                    error_log("..." . $u->getEmailPreferred());

                    try {
                        $message = \Swift_Message::newInstance()
                            ->setSubject("Tell us your Freegle story!")
                            ->setFrom([NOREPLY_ADDR => SITE_NAME])
                            ->setReturnPath($u->getBounce())
                            ->setTo([ $u->getEmailPreferred() => $u->getName() ])
                            ->setBody("We'd love to hear your Freegle story.  Tell us at $url");

                        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                        # Outlook.
                        $htmlPart = \Swift_MimePart::newInstance();
                        $htmlPart->setCharset('utf-8');
                        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                        $htmlPart->setContentType('text/html');
                        $htmlPart->setBody($html);
                        $message->attach($htmlPart);

                        Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::STORY_ASK, $u->getId());

                        list ($transport, $mailer) = Mail::getMailer();
                        $mailer->send($message);
                    } catch (\Exception $e) {}
                }
            }
        }

        return($asked);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM users_stories WHERE id = ?;", [ $this->id ]);
        return($rc);
    }

    public function sendIt($mailer, $message) {
        $mailer->send($message);
    }

    public function sendToCentral($id = NULL) {
        $idq = $id ? " AND id = $id " : "";
        $stories = $this->dbhr->preQuery("SELECT id FROM users_stories WHERE newsletter = 1 AND public = 1 AND newsletterreviewed = 1 AND mailedtomembers = 0 AND mailedtocentral = 0 $idq;");
        $url = "https://" . USER_SITE . "/stories/fornewsletter";
        $preview = "Please go to $url to vote for which go into the next member newsletter.";

        $thestories = [];

        foreach ($stories as $story) {
            $s = new Story($this->dbhr, $this->dbhm, $story['id']);
            $atts = $s->getPublic();

            $thestories[] = [
                'headline' => $atts['headline'],
                'story' => $atts['story'],
                'groupname' => $atts['groupname'],
                'photo' => Utils::presdef('photo', $atts, NULL) ? $atts['photo']['path'] : NULL
            ];

            $this->dbhm->preExec("UPDATE users_stories SET mailedtocentral = 1 WHERE id = ?;", [ $story['id'] ]);
        }

        if (count($thestories) > 0) {
            $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/stories');
            $twig = new \Twig_Environment($loader);

            $html = $twig->render('central.html', [
                'previewtext' => $preview,
                'vote' => $url,
                'stories' => $thestories
            ]);

            $message = \Swift_Message::newInstance()
                ->setSubject(date("d-M-Y")." Recent stories from freeglers - please vote")
                ->setFrom([CENTRAL_MAIL_FROM => SITE_NAME])
                ->setReturnPath(CENTRAL_MAIL_FROM)
                ->setTo('edward@ehibbert.org.uk')
                #->setTo(CENTRAL_MAIL_TO)
                ->setBody($preview);

            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
            # Outlook.
            $htmlPart = \Swift_MimePart::newInstance();
            $htmlPart->setCharset('utf-8');
            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
            $htmlPart->setContentType('text/html');
            $htmlPart->setBody($html);
            $message->attach($htmlPart);

            Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::STORY);

            list ($transport, $mailer) = Mail::getMailer();
            $this->sendIt($mailer, $message);
        }

        return(count($thestories));
    }

    public function generateNewsletter($min = 3, $max = 10, $id = NULL) {
        # We generate a newsletter from stories which have been marked as suitable for publication.
        $nid = NULL;
        $html = NULL;
        $count = 0;

        # Find the date of the last story sent in a newsletter; we're only interested in stories since then.
        $last = $this->dbhr->preQuery("SELECT MAX(created) AS max FROM newsletters WHERE type = 'Stories';");
        $since = $last[0]['max'];

        # Get unsent stories.  Pick the ones we have voted for most often.
        $idq = $id ? " AND users_stories.id = $id " : "";
        $sql = "SELECT users_stories.id, users_stories_images.id AS photoid, COUNT(*) AS count FROM users_stories LEFT JOIN users_stories_likes ON storyid = users_stories.id LEFT JOIN users_stories_images ON users_stories_images.storyid = users_stories.id WHERE newsletterreviewed = 1 AND newsletter = 1 AND mailedtomembers = 0 $idq AND (? IS NULL OR updated > ?) GROUP BY id ORDER BY count DESC LIMIT $max;";
        #error_log("$sql $since");
        $stories = $this->dbhr->preQuery($sql, [
            $since,
            $since
        ]);

        if (count($stories) >= $min) {
            # Enough to be worth sending a newsletter.
            shuffle($stories);

            $n = new Newsletter($this->dbhr, $this->dbhm);
            $preview = "This is a selection of recent stories from other freeglers.  If you can't read the HTML version, have a look at https://" . USER_SITE . '/stories';
            $nid = $n->create(NULL,
                "Lovely stories from other freeglers!",
                $preview);
            $n->setPrivate('type', 'Stories');

            $thestories = [];

            foreach ($stories as $story) {
                $s = new Story($this->dbhr, $this->dbhm, $story['id']);
                $atts = $s->getPublic();

                $thestories[] = [
                    'headline' => $atts['headline'],
                    'story' => $atts['story'],
                    'groupname' => $atts['groupname'],
                    'photo' => Utils::presdef('photo', $atts, NULL) ? $atts['photo']['path'] : NULL
                ];

                $count++;

                error_log("Sending {$story['id']}");
                $this->dbhm->preExec("UPDATE users_stories SET mailedtomembers = 1 WHERE id = ?;", [ $story['id'] ]);
            }

            $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/stories');
            $twig = new \Twig_Environment($loader);

            $img = rand(1, 5);
            $image = 'https://' . USER_SITE  . "/images/story$img.png";

            $html = $twig->render('newsletter.html', [
                'previewtext' => $preview,
                'headerimage' => $image,
                'tell' => 'https://' . USER_SITE . '/stories?src=storynewsletter',
                'give' => 'https://' . USER_SITE . '/give?src=storynewsletter',
                'find' => 'https://' . USER_SITE . '/find?src=storynewsletter',
                'email' => '{{email}}',
                'noemail' => '{{noemail}}',
                'stories' => $thestories
            ]);
        }

        return ($count >= $min ? [ $nid, $html ] : [ NULL, NULL ]);
    }

    public function like() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("INSERT IGNORE INTO users_stories_likes (storyid, userid) VALUES (?,?);", [
                $this->id,
                $me->getId()
            ]);
        }
    }

    public function unlike() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("DELETE FROM users_stories_likes WHERE storyid = ? AND userid = ?;", [
                $this->id,
                $me->getId()
            ]);
        }
    }
}