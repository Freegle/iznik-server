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
    var $publicatts = array('id', 'name', 'description', 'type', 'email');
    var $settableatts = array('name', 'description', 'email');

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

    public function create($name, $email, $description) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO teams (name, email, description) VALUES (?,?,?);", [
            $name,
            $email,
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

    public function findByName($name) {
        $ret = NULL;

        $teams = $this->dbhr->preQuery("SELECT * FROM teams WHERE name LIKE ?;", [
            $name
        ]);

        foreach ($teams as $team) {
            $ret = $team['id'];
        }

        return($ret);
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

    public function getVolunteers() {
        # A pseudo-team of all the volunteers who are ok with being public.  We dip into the user tables for performance,
        # otherwise we'd have to instantiate each user.
        $vols = $this->dbhr->preQuery("SELECT DISTINCT userid, firstname, lastname, fullname, users.added, users.settings FROM memberships INNER JOIN groups ON groups.id = memberships.groupid AND memberships.role IN (?, ?) INNER JOIN users ON users.id = memberships.userid WHERE groups.type = ?;", [
            User::ROLE_MODERATOR,
            User::ROLE_OWNER,
            Group::GROUP_FREEGLE
        ]);

        $ret = [];

        foreach ($vols as $vol) {
            $settings = json_decode($vol['settings'], true);

            # We want people who are happy to be shown as a mod, and also have a non-default profile.
            if (pres('showmod', $settings) && (!array_key_exists('useprofile', $settings) || $settings['useprofile'])) {
                $name = NULL;
                if ($vol['fullname']) {
                    $name = $vol['fullname'];
                } else if ($vol['firstname'] || $vol['lastname']) {
                    $name = $vol['firstname'] . ' ' . $vol['lastname'];
                }

                $profiles = $this->dbhr->preQuery("SELECT id, url, `default` FROM users_images WHERE userid = ? ORDER BY id DESC LIMIT 1;", [
                    $vol['userid']
                ]);

                if (count($profiles) > 0) {
                    # Anything we have wins
                    foreach ($profiles as $profile) {
                        if (!$profile['default']) {
                            # If it's a gravatar image we can return a thumbnail url that specifies a different size.
                            $turl = pres('url', $profile) ? $profile['url'] : ('https://' . IMAGE_DOMAIN . "/tuimg_{$profile['id']}.jpg");
                            $turl = strpos($turl, 'https://www.gravatar.com') === 0 ? str_replace('?s=200', '?s=100', $turl) : $turl;

                            $profile = [
                                'url' => pres('url', $profile) ? $profile['url'] : ('https://' . IMAGE_DOMAIN . "/uimg_{$profile['id']}.jpg"),
                                'turl' => $turl,
                                'default' => FALSE
                            ];
                        }
                    }
                } else {
                    $u = new User($this->dbhr, $this->dbhm, $vol['userid']);
                    $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
                    $u->ensureAvatar($atts);
                    $profile = $atts['profile'];
                }

                $ret[] = [
                    'userid' => $vol['userid'],
                    'added' => $vol['added'],
                    'displayname' => $name,
                    'profile' => $profile
                ];
            }
        }

        return($ret);
    }

    public function delete() {
        $this->dbhm->preExec("DELETE FROM teams WHERE id = ?;", [
            $this->id
        ]);
    }
}