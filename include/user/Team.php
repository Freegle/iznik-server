<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/mail/Newsletter.php');
require_once(IZNIK_BASE . '/mailtemplates/stories/story_ask.php');
require_once(IZNIK_BASE . '/mailtemplates/stories/story_central.php');
require_once(IZNIK_BASE . '/mailtemplates/stories/story_one.php');
require_once(IZNIK_BASE . '/mailtemplates/stories/story_newsletter.php');

class Team extends Entity
{
    var $publicatts = array('id', 'name', 'description');
    var $settableatts = array('name', 'description');

    const TEAM_BOARD = 'Board';
    const TEAM_GAT = 'GAT';
    const TEAM_MENTORS = 'Mentors';
    const TEAM_INFO = 'Info';
    const TEAM_GEEKS = 'Geeks';
    const TEAM_VOLUNTEERS = 'Volunteers';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'teams', 'team', $this->publicatts);
    }

    public function create($name, $description) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO teams (name, description) VALUES (?,?);", [
            $name,
            $description
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            if ($id) {
                $this->fetch($this->dbhm, $this->dbhm, $id, 'teams', 'team', $this->publicatts);
            }
        }

        return($id);
    }

    public function listAll() {
        $teams = $this->dbhr->preQuery("SELECT * FROM teams ORDER BY LOWER(name) ASC;", []);

        return($teams);
    }

    public function getPublic() {
        $ret = parent::getPublic();

        return($ret);
    }

    public function addMember($userid, $desc = NULL) {
        $this->dbhm->preExec("REPLACE INTO teams_members (userid, teamid, description) VALUES (?, ?, ?);", [
            $userid,
            $this->id,
            $desc
        ]);

        return($this->dbhm->lastInsertId());
    }

    public function removeMember($userid) {
        $this->dbhm->preExec("DELETE FROM teams_members WHERE userid = ? AND teamid = ?;", [
            $userid,
            $this->id
        ]);
    }

    public function getMembers() {
        $membs = $this->dbhr->preQuery("SELECT userid, description, added, nameoverride, imageoverride FROM teams_members WHERE teamid = ?;", [
            $this->id
        ]);

        return(count($membs) > 0 ? $membs : NULL);
    }

    public function delete() {
        $this->dbhm->preExec("DELETE FROM teams WHERE id = ?;", [
            $this->id
        ]);
    }
}