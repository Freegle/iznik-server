<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/newsfeed/Newsfeed.php');

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
(?,?,?,GeomFromText('POINT($lng $lat)'), NOW(), ?, ?, 1, GeomFromText('POINT($lng $lat)'));", [
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
            $ctx = NULL;
            $atts['addedby'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);
        }

        $atts['added'] = ISODate($atts['added']);
        $atts['lastcheckedat'] = ISODate($atts['lastcheckedat']);

        # Get any info.
        $atts['checks'] = $this->dbhr->preQuery("SELECT * FROM noticeboard_checks WHERE noticeboardid = ? ORDER BY id DESC;", [
            $this->id
        ]);

        foreach ($atts['checks'] as &$check) {
            foreach (['askedat', 'checkedat'] as $time) {
                $check[$time] = ISODate($check[$time]);
            }

            if ($check['userid']) {
                $u = User::get($this->dbhr, $this->dbhm, $check['userid']);
                $ctx = NULL;
                $check['user'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);
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
        $loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new Twig_Environment($loader);

        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $subj = "Thanks for putting up a poster!";

        $html = $twig->render('noticeboard.html', [
            'settings' => $u->loginLink(USER_SITE, $u->getId(), '/settings', User::SRC_NOTICEBOARD),
            'id' => $noticeboardid,
            'email' => $u->getEmailPreferred()
        ]);

        $message = Swift_Message::newInstance()
            ->setSubject($subj)
            ->setFrom([NOREPLY_ADDR => 'Freegle'])
            ->setReturnPath($u->getBounce())
            ->setTo([ $u->getEmailPreferred() => $u->getName() ])
            ->setBody("Thanks for putting up a poster!");

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        Mail::addHeaders($message, Mail::NOTICEBOARD, $u->getId());

        list ($transport, $mailer) = getMailer();
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
                $this->dbhm->preExec("INSERT INTO noticeboard_checks (noticeboardid, userid, checkedat, refreshed) VALUES (?, ?, NOW(), 1);", [
                    $id,
                    $userid
                ]);
                break;
            }
            case self::ACTION_DECLINED: {
                $this->dbhm->preExec("INSERT INTO noticeboard_checks (noticeboardid, userid, checkedat, declined) VALUES (?, ?, NOW(), 1);", [
                    $id,
                    $userid
                ]);
                break;
            }
            case self::ACTION_INACTIVE: {
                $this->dbhm->preExec("INSERT INTO noticeboard_checks (noticeboardid, userid, checkedat, inactive) VALUES (?, ?, NOW(), 1);", [
                    $id,
                    $userid
                ]);
                break;
            }
            case self::ACTION_COMMENTS: {
                $this->dbhm->preExec("INSERT INTO noticeboard_checks (noticeboardid, userid, checkedat, comments) VALUES (?, ?, NOW(), ?);", [
                    $id,
                    $userid,
                    $comments
                ]);
                break;
            }
        }
    }
}

