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

    public function create($name, $lat, $lng, $addedby, $description) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO noticeboards (`name`, `lat`, `lng`, `position`, `added`, `addedby`, `description`, `active`, `lastcheckedat`) VALUES 
(?,?,?,GeomFromText('POINT($lng $lat)'), NOW(), ?, ?, 1, NOW());", [
            $name, $lat, $lng, $addedby, $description
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

    public function addNews()
    {
        $n = new Newsfeed($this->dbhr, $this->dbhm);
        $toenc = $this->noticeboard;
        unset($toenc['position']);
        $n->create(Newsfeed::TYPE_NOTICEBOARD, $this->noticeboard['addedby'], json_encode($toenc), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, $this->noticeboard['lat'], $this->noticeboard['lng']);
        return($n);
    }

    public function setAttributes($settings)
    {
        # Create news on the first real change.
        $addnews = !$this->noticeboard['name'] && $settings['name'];

        parent::setAttributes($settings);

        if ($addnews) {
            // Now that we have some info, generate a newsfeed item.
            $this->addNews();
        }
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

        Mail::addHeaders($message, Mail::NOTICEBOARD, $u->getId());

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

    public function listAll() {
        return($this->dbhr->preQuery("SELECT id, name, lat, lng FROM noticeboards WHERE name IS NOT NULL AND active = 1"));
    }

    public function action($id, $userid, $action, $comments = NULL) {
        switch ($action) {
            case self::ACTION_REFRESHED: {
                $this->dbhm->preExec("INSERT INTO noticeboards_checks (noticeboardid, userid, checkedat, refreshed) VALUES (?, ?, NOW(), 1);", [
                    $id,
                    $userid
                ]);

                # This noticeboard has been checked.
                $this->dbhm->preExec("UPDATE noticeboards SET lastcheckedat = NOW() WHERE id = ?", [
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
        $mysqltime = date('Y-m-d', strtotime("7 days ago"));

        $checks = $this->dbhr->preQuery("SELECT * FROM noticeboards_checks WHERE userid = ? AND askedat >= ?;", [
            $userid,
            $mysqltime
        ]);

        return count($checks) > 0;
    }

    public function chaseup($id = NULL) {
        $count = 0;

        # Chase up the person who put a poster up.  They're the most likely person to replace it.  We want noticeboards
        # to be checked once a month.
        $mysqltime = date('Y-m-d', strtotime("30 days ago"));
        $idq = $id ? " AND noticeboards.id = $id " : '';
        $noticeboards = $this->dbhr->preQuery("SELECT * FROM noticeboards WHERE ((added <= ? AND lastcheckedat IS NULL) OR (lastcheckedat IS NOT NULL AND lastcheckedat < ?)) AND active = 1 AND name IS NOT NULL $idq;", [
            $mysqltime,
            $mysqltime
        ]);

        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/noticeboard/');
        $twig = new \Twig_Environment($loader);

        foreach ($noticeboards as $noticeboard) {
            # See if we've asked anyone about this one in the last week.  If so then we don't do anything, because
            # we want to give them time to act.
            error_log("Check noticeboard {$noticeboard['id']} {$noticeboard['name']} added {$noticeboard['added']} last checked {$noticeboard['lastcheckedat']}");
            $mysqltime2 = date('Y-m-d', strtotime("7 days ago"));
            $checks = $this->dbhr->preQuery("SELECT * FROM noticeboards_checks WHERE noticeboardid = ? AND askedat >= ?;", [
                $noticeboard['id'],
                $mysqltime2
            ]);

            if (!count($checks)) {
                # We haven't.  First choice is the person who put it up.  See if we've asked them.
                error_log("...no recent checks");

                $findone = TRUE;

                if ($noticeboard['addedby'] && !$this->asked($noticeboard['addedby'], $noticeboard['id']) && !$this->askedRecently($noticeboard['addedby'])) {
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
                                ->setBody("A while ago you put up a poster for Freegle.  Could you put another one up in the same please?  Click https://www.ilovefreegle.org/noticeboards/{$noticeboard['id']} to let us know...");

                            $htmlPart = \Swift_MimePart::newInstance();
                            $htmlPart->setCharset('utf-8');
                            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                            $htmlPart->setContentType('text/html');
                            $htmlPart->setBody($html);
                            $message->attach($htmlPart);

                            Mail::addHeaders($message, Mail::NOTICEBOARD_CHASEUP_OWNER, $u->getId());

                            list ($transport, $mailer) = Mail::getMailer();
                            $this->sendIt($mailer, $message);

                            # Record our ask.
                            $this->dbhm->preExec("INSERT INTO noticeboards_checks (noticeboardid, userid, askedat) VALUES (?, ?, NOW());", [
                                $noticeboard['id'],
                                $noticeboard['addedby']
                            ]);

                            $findone = FALSE;
                            $count++;
                        }
                    }
                }

                if ($findone) {
                    # We've asked the owner if they're still around.  Find someone else.
                    # TODO
                }
            }
        }

        return $count;
    }
}

