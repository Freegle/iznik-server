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
    }

    public function setAttributes($settings)
    {
        parent::setAttributes($settings);

        // Now that we have some info, generate a newsfeed item.
        $this->addNews();
    }
}

