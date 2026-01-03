<?php
namespace Freegle\Iznik;



class Noticeboard extends Entity
{
    const ACTION_REFRESHED = 'Refreshed';
    const ACTION_DECLINED = 'Declined';
    const ACTION_COMMENTS = 'Comments';
    const ACTION_INACTIVE = 'Inactive';

    /** @var  $dbhm LoggedPDO */
    public $publicatts = [ 'id', 'name', 'lat', 'lng', 'added', 'position', 'addedby', 'description', 'active', 'lastcheckedat'];
    public $settableatts = [ 'name', 'lat', 'lng', 'description', 'active', 'lastcheckedat'];
    var $event;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'noticeboards', 'noticeboard', $this->publicatts);
    }

    public function create($name, $lat, $lng, $addedby, $description, $active = TRUE) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO noticeboards (`name`, `lat`, `lng`, `position`, `added`, `addedby`, `description`, `active`, `lastcheckedat`) VALUES 
(?,?,?,ST_GeomFromText('POINT($lng $lat)', {$this->dbhr->SRID()}), NOW(), ?, ?, ?, NOW());", [
            $name, $lat, $lng, $addedby, $description, $active
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();
            $this->fetch($this->dbhm, $this->dbhm, $id, 'noticeboards', 'noticeboard', $this->publicatts);
        }

        return($id);
    }

    public function getPublic()
    {
        $atts = parent::getPublic();

        if ($atts['addedby']) {
            $u = User::get($this->dbhr, $this->dbhm, $atts['addedby']);
            $atts['addedby'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);
        }

        $atts['added'] = Utils::ISODate($atts['added']);
        $atts['lastcheckedat'] = Utils::ISODate($atts['lastcheckedat']);

        $photos = $this->dbhr->preQuery("SELECT id FROM noticeboards_images WHERE noticeboardid = ?;", [ $this->id ]);
        foreach ($photos as $photo) {
            $a = new Attachment($this->dbhr, $this->dbhm, $photo['id'], Attachment::TYPE_NOTICEBOARD);

            $atts['photo'] = [
                'id' => $photo['id'],
                'path' => $a->getPath(FALSE),
                'paththumb' => $a->getPath(TRUE)
            ];
        }

        # Get any info.
        $atts['checks'] = $this->dbhr->preQuery("SELECT * FROM noticeboards_checks WHERE noticeboardid = ? ORDER BY id DESC;", [
            $this->id
        ]);

        foreach ($atts['checks'] as &$check) {
            foreach (['askedat', 'checkedat'] as $time) {
                $check[$time] = Utils::ISODate($check[$time]);
            }

            if ($check['userid']) {
                $u = User::get($this->dbhr, $this->dbhm, $check['userid']);
                $check['user'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);
                $check['userid'] = NULL;
            }
        }

        return($atts);
    }

    public function addNews($photoid)
    {
        $n = new Newsfeed($this->dbhr, $this->dbhm);
        $toenc = $this->noticeboard;

        if ($photoid) {
            $full = $this->dbhr->preQuery("SELECT * FROM noticeboards_images WHERE id = ? AND externaluid IS NOT NULL;", [ $photoid ]);
            $toenc['photo'] = $photoid;
            $toenc['photofull'] = $full[0];
        }

        unset($toenc['position']);
        $n->create(Newsfeed::TYPE_NOTICEBOARD, $this->noticeboard['addedby'], json_encode($toenc), NULL, NULL, NULL, NULL, NULL, NULL, NULL, $this->noticeboard['lat'], $this->noticeboard['lng']);
        return($n);
    }

    public function setAttributes($settings)
    {
        # Create news on the first real change.
        $addnews = !$this->noticeboard['name'] && $settings['name'];

        parent::setAttributes($settings);

        if ($addnews) {
            if (Utils::presbool('active', $settings, TRUE)) {
                // Now that we have some info, generate a newsfeed item.
                $this->addNews(Utils::presdef('photoid', $settings, NULL));
            }
        }
    }

    public function setPhoto($photoid) {
        $this->dbhm->preExec("UPDATE noticeboards_images SET noticeboardid = ? WHERE id = ?;", [ $this->id, $photoid ]);
    }

    public function thank($userid, $noticeboardid) {
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/noticeboard');
        $twig = new \Twig_Environment($loader);

        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $subj = "Thanks for putting up a poster!";

        $html = $twig->render('thanks.html', [
            'settings' => $u->loginLink(USER_SITE, $u->getId(), '/settings', User::SRC_NOTICEBOARD),
            'id' => $noticeboardid,
            'email' => $u->getEmailPreferred()
        ]);

        $message = \Swift_Message::newInstance()
            ->setSubject($subj)
            ->setFrom([NOREPLY_ADDR => 'Freegle'])
            ->setReturnPath($u->getBounce())
            ->setTo([ $u->getEmailPreferred() => $u->getName() ])
            ->setBody("Thanks for putting up a poster!");

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::NOTICEBOARD, $u->getId());

        list ($transport, $mailer) = Mail::getMailer();
        $this->sendIt($mailer, $message);
        $this->dbhm->preExec("UPDATE noticeboards SET thanked = NOW() WHERE addedby = ?;", [
            $userid
        ]);

        error_log($u->getEmailPreferred());
    }

    public function sendIt($mailer, $message) {
        $mailer->send($message);
    }

    public function listAll($authorityid) {
        if (!$authorityid) {
            $ret = $this->dbhr->preQuery("SELECT id, name, lat, lng FROM noticeboards WHERE name IS NOT NULL AND active = 1");
        } else {
            $ret = $this->dbhr->preQuery("SELECT noticeboards.id, noticeboards.name, noticeboards.lat, noticeboards.lng FROM noticeboards  
              INNER JOIN authorities ON authorities.id = ?  
              WHERE authorities.name IS NOT NULL AND active = 1 AND ST_CONTAINS(authorities.polygon, ST_SRID(POINT(noticeboards.lng, noticeboards.lat), ?));", [
                  $authorityid,
                $this->dbhr->SRID()
            ]);
        }

        return($ret);
    }

    public function action($id, $userid, $action, $comments = NULL) {
        switch ($action) {
            case self::ACTION_REFRESHED: {
                $this->dbhm->preExec("INSERT INTO noticeboards_checks (noticeboardid, userid, checkedat, refreshed) VALUES (?, ?, NOW(), 1);", [
                    $id,
                    $userid
                ]);

                # This noticeboard has been checked.
                $this->dbhm->preExec("UPDATE noticeboards SET lastcheckedat = NOW(), active = 1 WHERE id = ?", [
                    $id
                ]);
                break;
            }
            case self::ACTION_DECLINED: {
                $this->dbhm->preExec("INSERT INTO noticeboards_checks (noticeboardid, userid, checkedat, declined) VALUES (?, ?, NOW(), 1);", [
                    $id,
                    $userid
                ]);
                break;
            }
            case self::ACTION_INACTIVE: {
                $this->dbhm->preExec("INSERT INTO noticeboards_checks (noticeboardid, userid, checkedat, inactive) VALUES (?, ?, NOW(), 1);", [
                    $id,
                    $userid
                ]);

                # This noticeboard has been checked.
                $this->dbhm->preExec("UPDATE noticeboards SET lastcheckedat = NOW(), active = 0 WHERE id = ?", [
                    $id
                ]);
                break;
            }
            case self::ACTION_COMMENTS: {
                $this->dbhm->preExec("INSERT INTO noticeboards_checks (noticeboardid, userid, checkedat, comments) VALUES (?, ?, NOW(), ?);", [
                    $id,
                    $userid,
                    $comments
                ]);
                break;
            }
        }
    }

    private function asked($userid, $noticeboardid) {
        $checks = $this->dbhr->preQuery("SELECT * FROM noticeboards_checks WHERE noticeboardid = ? AND userid = ?;", [
            $noticeboardid,
            $userid
        ]);

        return count($checks) > 0;
    }

    private function askedRecently($userid) {
        $mysqltime = date('Y-m-d', strtotime("14 days ago"));

        $checks = $this->dbhr->preQuery("SELECT * FROM noticeboards_checks WHERE userid = ? AND askedat >= ?;", [
            $userid,
            $mysqltime
        ]);

        return count($checks) > 0;
    }

    public function chaseup($id = NULL, $others = FALSE, $groupid = NULL) {
        $count = 0;

        # Chase up the person who put a poster up.  They're the most likely person to replace it.  We want noticeboards
        # to be checked once a month.
        $mysqltime = date('Y-m-d', strtotime("30 days ago"));
        $idq = $id ? " AND noticeboards.id = $id " : '';
        $noticeboards = $this->dbhr->preQuery("SELECT * FROM noticeboards WHERE 
                             ((added <= ? AND lastcheckedat IS NULL) OR (lastcheckedat IS NOT NULL AND lastcheckedat < ?)) 
                             AND active = 1 AND name IS NOT NULL $idq;", [
            $mysqltime,
            $mysqltime
        ]);

        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/noticeboard/');
        $twig = new \Twig_Environment($loader);

        # Get users who
        # - have been active recently
        # - haven't been asked recently
        # - are in the group
        # ...and their lat/lngs.
        $latlngs = [];

        $askedat = date('Y-m-d', strtotime("7 days ago"));
        $activeat = date('Y-m-d', strtotime("90 days ago"));
        $users = $this->dbhr->preQuery("SELECT DISTINCT(users.id) FROM users
    INNER JOIN memberships ON users.id = memberships.userid
    LEFT JOIN noticeboards_checks ON users.id = noticeboards_checks.userid
    WHERE 
    groupid = ? 
    AND lastaccess >= ? AND users.deleted IS NULL 
    AND (askedat IS NULL OR askedat < ?) ", [
            $groupid,
            $activeat,
            $askedat
        ]);

        foreach ($users as $user) {
            $u = User::get($this->dbhr, $this->dbhm, $user['id']);

            if ($u->getEmailPreferred() && $u->sendOurMails()) {
                list ($lat, $lng, $loc) = $u->getLatLng();

                if ($lat || $lng) {
                    $latlngs[$user['id']] = [ $lat, $lng ];
                }
            }
        }

        foreach ($noticeboards as $noticeboard) {
            # See if we've asked anyone about this one recently.  If so then we don't do anything, because
            # we want to give them time to act.
            error_log("Check noticeboard {$noticeboard['id']} {$noticeboard['name']} added {$noticeboard['added']} last checked {$noticeboard['lastcheckedat']}");
            $mysqltime2 = date('Y-m-d', strtotime("4 days ago"));
            $checks = $this->dbhr->preQuery("SELECT * FROM noticeboards_checks WHERE noticeboardid = ? AND askedat >= ?;", [
                $noticeboard['id'],
                $mysqltime2
            ]);

            if (!count($checks)) {
                # We haven't.  First choice is the person who put it up.  See if we've asked them.
                error_log("...no recent checks");

                $findone = TRUE;

                if (!$others &&
                    $noticeboard['addedby'] &&
                    !$this->asked($noticeboard['addedby'], $noticeboard['id']) &&
                    !$this->askedRecently($noticeboard['addedby'])) {
                    # We haven't.  Ask them now.
                    error_log("...ask owner {$noticeboard['addedby']}");
                    $u = User::get($this->dbhr, $this->dbhm, $noticeboard['addedby']);

                    if ($u->getId() && !$u->getPrivate('deleted')) {
                        $html = $twig->render('chaseup_owner.html', [
                            'email' => $u->getEmailPreferred(),
                            'id' => $noticeboard['id'],
                            'name' => $noticeboard['name'],
                            'description' => $noticeboard['description']
                        ]);

                        if ($u->getEmailPreferred() && $u->sendOurMails()) {
                            error_log("...ask owner " . $u->getEmailPreferred());
                            $message = \Swift_Message::newInstance()
                                ->setSubject('That poster you put up...')
                                ->setFrom([NOREPLY_ADDR => 'Freegle'])
                                ->setReturnPath($u->getBounce())
                                ->setTo([ $u->getEmailPreferred() => $u->getName() ])
                                ->setBody("A while ago you put up a poster for Freegle.  Could you put another one up in the same place please?  Click https://www.ilovefreegle.org/noticeboards/{$noticeboard['id']} to let us know...");

                            $htmlPart = \Swift_MimePart::newInstance();
                            $htmlPart->setCharset('utf-8');
                            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                            $htmlPart->setContentType('text/html');
                            $htmlPart->setBody($html);
                            $message->attach($htmlPart);

                            Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::NOTICEBOARD_CHASEUP_OWNER, $u->getId());

                            list ($transport, $mailer) = Mail::getMailer();
                            $this->sendIt($mailer, $message);

                            # Record our ask.
                            $this->recordAsk($noticeboard['id'], $noticeboard['addedby']);

                            $findone = FALSE;
                            $count++;
                        }
                    }
                }

                if ($findone && $groupid) {
                    # See if this noticeboard is within the area of the group.
                    $within = $this->dbhr->preQuery("SELECT id FROM `groups` WHERE id = ? 
                          AND ST_Contains(ST_GeomFromText(CASE WHEN poly IS NOT NULL THEN poly ELSE polyofficial END, ?), ST_SRID(POINT(?, ?), ?));", [
                        $groupid,
                        $this->dbhr->SRID(),
                        $noticeboard['lng'],
                        $noticeboard['lat'],
                        $this->dbhr->SRID()
                    ]);

                    if (count($within)) {
                        # Now find the closest user we've not asked recently or about this one.
                        $closestid = NULL;
                        $closestdist = NULL;

                        foreach ($latlngs as $uid => $latlng) {
                            list ($lat, $lng) = $latlng;
                            $away = \GreatCircle::getDistance($noticeboard['lat'], $noticeboard['lng'], $lat, $lng);

                            if (($lat || $lng) && (!$closestid || $away < $closestdist) &&
                                !$this->askedRecently($uid) &&
                                !$this->asked($uid, $noticeboard['id'])) {
                                $closestid = $uid;
                                $closestdist = $away;
                            }
                        }

                        if ($closestid) {
                            $u = User::get($this->dbhr, $this->dbhm, $closestid);
                            $n = new Noticeboard($this->dbhr, $this->dbhm, $noticeboard['id']);
                            $atts = $n->getPublic();

                            $html = $twig->render('chaseup_other.html', [
                                'email' => $u->getEmailPreferred(),
                                'id' => $noticeboard['id'],
                                'name' => $noticeboard['name'],
                                'description' => $noticeboard['description'],
                                'photo' => array_key_exists('photo', $atts) ? $atts['photo']['photo'] : NULL,
                            ]);

                            $miles = $closestdist / 1609.344;
                            $miles = $miles > 2 ? round($miles) : round($miles, 1);

                            $message = \Swift_Message::newInstance()
                                ->setSubject("Can you help?  There's a noticeboard $miles miles from you - " . $atts['name'])
                                ->setFrom([NOREPLY_ADDR => 'Freegle'])
                                ->setReturnPath($u->getBounce())
                                ->setTo([ $u->getEmailPreferred() => $u->getName() ])
                                ->setBody("Someone printed and put up a Freegle poster on it a while back.  Could you keep it alive by printing one and putting it up?  Click https://www.ilovefreegle.org/noticeboards/{$noticeboard['id']} to let us know...");

                            $htmlPart = \Swift_MimePart::newInstance();
                            $htmlPart->setCharset('utf-8');
                            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                            $htmlPart->setContentType('text/html');
                            $htmlPart->setBody($html);
                            $message->attach($htmlPart);

                            Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::NOTICEBOARD_CHASEUP_OWNER, $u->getId());

                            list ($transport, $mailer) = Mail::getMailer();
                            error_log("...ask $closestid away $closestdist for noticeboard {$noticeboard['id']}");
                            $this->sendIt($mailer, $message);

                            # Record our ask.
                            $this->recordAsk($noticeboard['id'], $closestid);
                            $count++;
                        }
                    }
                }
            }
        }

        return $count;
    }

    private function recordAsk($id, $userid): void
    {
        $this->dbhm->preExec("INSERT INTO noticeboards_checks (noticeboardid, userid, askedat) VALUES (?, ?, NOW());", [
            $id,
            $userid
        ]);
    }
}

