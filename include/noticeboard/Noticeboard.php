<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/newsfeed/Newsfeed.php');

class Noticeboard extends Entity
{
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

        $atts['addedby'] = ISODate($atts['added']);
        $atts['lastcheckedat'] = ISODate($atts['lastcheckedat']);

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
        parent::setAttributes($settings);

        // Now that we have some info, generate a newsfeed item.
        $this->addNews();
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
}

