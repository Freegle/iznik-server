<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

class Shortlink extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'name', 'type', 'groupid', 'url', 'clicks', 'created');
    var $settableatts = array('name');

    const TYPE_GROUP = 'Group';
    const TYPE_OTHER = 'Other';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'shortlinks', 'shortlink', $this->publicatts);
    }

    public function create($name, $type, $groupid = NULL, $url = NULL) {
        $ret = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO shortlinks (name, type, groupid, url) VALUES (?,?,?,?);", [
            $name,
            $type,
            $groupid,
            $url
        ]);

        $id = $this->dbhm->lastInsertId();

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'shortlinks', 'shortlink', $this->publicatts);
            $ret = $id;
        }

        return($ret);
    }

    public function resolve($name) {
        $url = NULL;
        $id = NULL;
        $links = $this->dbhr->preQuery("SELECT * FROM shortlinks WHERE name LIKE ?;", [ $name ]);
        foreach ($links as $link) {
            $id = $link['id'];
            if ($link['type'] == Shortlink::TYPE_GROUP) {
                $g = new Group($this->dbhr, $this->dbhm, $link['groupid']);

                # Where we redirect to depends on the group settings.
                $url = $g->getPrivate('onhere') ? ('https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort')) : ('https://groups.yahoo.com/' . $g->getPrivate('nameshort'));
            } else {
                $url = $link['url'];

                if (strpos($url, 'http://groups.yahoo.com/group/') === 0) {
                    $url = str_replace('http://groups.yahoo.com/group/', 'http://groups.yahoo.com/neo/groups/', $url);
                }
            }

            $this->dbhm->background("UPDATE shortlinks SET clicks = clicks + 1 WHERE id = {$link['id']};");
            $this->dbhm->background("INSERT INTO shortlink_clicks (shortlinkid) VALUES ({$link['id']});");
        }

        return([$id, $url]);
    }

    public function listAll() {
        $links = $this->dbhr->preQuery("SELECT * FROM shortlinks ORDER BY LOWER(name) ASC;");
        foreach ($links as &$link) {
            $id = $link['id'];
            if ($link['type'] == Shortlink::TYPE_GROUP) {
                $g = new Group($this->dbhr, $this->dbhm, $link['groupid']);
                $link['nameshort'] = $g->getPrivate('nameshort');

                # Where we redirect to depends on the group settings.
                $link['url'] = $g->getPrivate('onhere') ? ('https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort')) : ('https://groups.yahoo.com/neo/groups' . $g->getPrivate('nameshort'));
            }
        }

        return($links);
    }

    public function getPublic() {
        $ret = $this->getAtts($this->publicatts);
        $ret['created'] = ISODate($ret['created']);
        $clickhistory = $this->dbhr->preQuery("SELECT DATE(timestamp) AS date, COUNT(*) AS count FROM `shortlink_clicks` GROUP BY date ORDER BY date ASC", NULL, FALSE, FALSE);
        foreach ($clickhistory as &$c) {
            $c['date'] = ISODate($c['date']);
        }
        $ret['clickhistory'] = $clickhistory;
        return($ret);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM shortlinks WHERE id = ?;", [$this->id]);
        return($rc);
    }
}