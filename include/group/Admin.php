<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/misc/Mail.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/user/MembershipCollection.php');
require_once(IZNIK_BASE . '/mailtemplates/admin.php');

class Admin extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'groupid', 'created', 'complete', 'subject', 'text', 'createdby', 'pending', 'parentid');
    var $settableatts = [ 'subject', 'text', 'pending' ];

    /** @var  $log Log */
    private $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'admins', 'admin', $this->publicatts);
        $this->log = new Log($dbhr, $dbhm);
    }

    public function create($groupid, $createdby, $subject, $text) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO admins (`groupid`, `createdby`, `subject`, `text`) VALUES (?,?,?,?);", [
            $groupid, $createdby, $subject, $text
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();
            $this->fetch($this->dbhm, $this->dbhm, $id, 'admins', 'admin', $this->publicatts);
        }

        return($id);
    }

    public function getPublic() {
        $atts = parent::getPublic();

        if (pres('createdby', $atts)) {
            $u = User::get($this->dbhr, $this->dbhm, $atts['createdby']);
            
            if ($u->getId() == $atts['createdby']) {
                $ctx = NULL;
                $atts['createdby'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
            }

            $atts['created'] = ISODate($atts['created']);
        }

        return($atts);
    }

    public function constructMessage($groupname, $to, $toname, $from, $subject, $text) {
        $post = "https://" . USER_SITE;
        $unsubscribe = "https://" . USER_SITE . "/unsubscribe";
        $visit = "https://" .  USER_SITE . "/mygroups";

        $text = str_replace('$groupname', $groupname, $text);

        $html = admin_tpl($groupname, $toname, $to, 'https://' . USER_SITE, USERLOGO, $subject, nl2br($text), $post, $unsubscribe, $visit);
        $message = Swift_Message::newInstance()
            ->setSubject("ADMIN: $subject")
            ->setFrom([$from => "$groupname Volunteers" ])
            ->setTo([$to => $toname])
            ->setBody($text);

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        return($message);
    }

    public function process($id = NULL) {
        $done = 0;
        $idq = $id ? " id = $id AND " : '';
        $sql = "SELECT * FROM admins WHERE $idq complete IS NULL AND pending = 0;";
        $admins = $this->dbhr->preQuery($sql);

        foreach ($admins as $admin) {
            $a = new Admin($this->dbhr, $this->dbhm, $admin['id']);
            $done += $a->mailMembers();
            $this->dbhm->preExec("UPDATE admins SET complete = NOW() WHERE id = ?;", [ $admin['id'] ]);
        }

        return($done);
    }

    public function mailMembers() {
        list ($transport, $mailer) = getMailer();
        $done = 0;
        $groupid = $this->admin['groupid'];

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $atts = $g->getPublic();

        $groupname = $atts['namedisplay'];
        $onyahoo = $atts['onyahoo'];

        $sql = "SELECT userid FROM memberships WHERE groupid = ?;";
        $members = $this->dbhr->preQuery($sql, [ $groupid ]);

        foreach ($members as $member) {
            $u = User::get($this->dbhr, $this->dbhm, $member['userid']);

            #error_log("Consider {$member['userid']} parent {$this->admin['parentid']}");
            if ($this->admin['parentid']) {
                # This is a suggested admin, where we create copies for each group for them to edit/approve/reject
                # as they see fit.  We don't want to send many copies to the same user if they happen to be on
                # multiple groups, so check whether we've sent this kind of admin to them.
                #error_log("Check sent {$this->admin['parentid']} to {$member['userid']}");
                $sent = $this->dbhr->preQuery("SELECT * FROM admins_users WHERE adminid = ? AND userid = ?;", [
                    $this->admin['parentid'],
                    $member['userid']
                ], FALSE, FALSE);

                if (count($sent) > 0) {
                    # We have - skip
                    #error_log("Already sent");
                    continue;
                }
            }

            $preferred = $u->getEmailPreferred();

            # We send to members who have joined via our platform, or to all users if we host the group.
            list ($eid, $ouremail) = $u->getEmailForYahooGroup($groupid, TRUE, TRUE);

            if ($preferred && ($ouremail || !$onyahoo)) {
                try {
                    $msg = $this->constructMessage($groupname, $preferred, $u->getName(), $g->getAutoEmail(), $this->admin['subject'], $this->admin['text']);

                    Mail::addHeaders($msg, Mail::ADMIN, $u->getId());
                    $mailer->send($msg);

                    if ($this->admin['parentid']) {
                        # Record that we've sent this kind of admin.
                        $this->dbhm->preExec("INSERT INTO admins_users (userid, adminid) VALUES (?, ?);", [
                            $member['userid'],
                            $this->admin['parentid']
                        ]);
                        #error_log("Record sent {$this->admin['parentid']} to {$member['userid']}");
                    }

                    $done++;
                } catch (Exception $e) {
                    error_log("Failed with " . $e->getMessage());
                }
            }
        }

        return($done);
    }

    public function updateEdit() {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $this->dbhm->preExec("UPDATE admins SET editedat = NOW(), editedby = ?;", [
            $me->getId()
        ]);
    }

    public function copyForGroup($groupid) {
        # We have a suggested admin, and we want to create a per-group copy.  This allows local groups to
        # edit/approve/reject as they see fit.
        $a = new Admin($this->dbhr, $this->dbhm);
        $id = $a->create($groupid, NULL, $this->admin['subject'], $this->admin['text']);

        if ($id) {
            $a->setPrivate('parentid', $this->id);
        }

        return($id);
    }

    public function listForGroup($groupid) {
        $admins = $this->dbhr->preQuery("SELECT id FROM admins WHERE groupid = ? ORDER BY created DESC;", [ $groupid ]);
        $ret = [];

        foreach ($admins as $admin) {
            $a = new Admin($this->dbhr, $this->dbhm, $admin['id']);
            $ret[] = $a->getPublic();
        }

        return($ret);
    }

    public function listPending($userid) {
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $membs = $u->getMemberships(TRUE, NULL, TRUE);
        $ret = [];
        $admins = [];

        foreach ($membs as $memb) {
            if ($memb['work']['pendingadmins']) {
                $admins = array_merge($admins, $this->dbhr->preQuery("SELECT id FROM admins WHERE groupid = ? AND pending = 1 AND complete IS NULL ORDER BY created DESC;", [ $memb['id']]));
            }
        }

        foreach ($admins as $admin) {
            $a = new Admin($this->dbhr, $this->dbhm, $admin['id']);
            $ret[] = $a->getPublic();
        }

        return($ret);
    }

    public function delete() {
        $this->dbhm->preExec("DELETE FROM admins WHERE id = ?;", [
            $this->id
        ]);
    }
}

