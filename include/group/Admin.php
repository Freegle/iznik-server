<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/admin.php');

class Admin extends Entity
{
    const SPOOLERS = 10;
    const SPOOLNAME = '/spool_admin_';

    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'groupid', 'created', 'complete', 'subject', 'text', 'createdby', 'pending', 'parentid', 'heldby', 'heldat');
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

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        error_log("Admin notify $groupid");
        $n->notifyGroupMods($groupid);

        return($id);
    }

    public function getPublic() {
        $atts = parent::getPublic();

        if (Utils::pres('createdby', $atts)) {
            $u = User::get($this->dbhr, $this->dbhm, $atts['createdby']);
            
            if ($u->getId() == $atts['createdby']) {
                $atts['createdby'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
            }

            $atts['created'] = Utils::ISODate($atts['created']);
        }

        return($atts);
    }

    public function constructMessage($groupname, $to, $toname, $from, $subject, $text) {
        $post = "https://" . USER_SITE;
        $unsubscribe = "https://" . USER_SITE . "/unsubscribe";
        $visit = "https://" .  USER_SITE . "/mygroups";

        $text = str_replace('$groupname', $groupname, $text);
        $subject = str_replace('ADMIN: ', '', $subject);
        $subject = str_replace('ADMIN ', '', $subject);

        $html = admin_tpl($groupname, $toname, $to, 'https://' . USER_SITE, USERLOGO, $subject, nl2br($text), $post, $unsubscribe, $visit);
        $message = \Swift_Message::newInstance()
            ->setSubject("ADMIN: $subject")
            ->setFrom([$from => "$groupname Volunteers" ])
            ->setTo([$to => $toname])
            ->setBody($text);

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        return($message);
    }

    public function process($id = NULL, $force = FALSE, $gently = FALSE) {
        $done = 0;
        $idq = $id ? " id = $id AND " : '';
        $sql = "SELECT * FROM admins WHERE $idq complete IS NULL AND pending = 0;";
        $admins = $this->dbhr->preQuery($sql);

        foreach ($admins as $admin) {
            $g = new Group($this->dbhr, $this->dbhm, $admin['groupid']);

            if ($force || ($g->getPrivate('onhere') && $g->getPrivate('publish') && !$g->getPrivate('external'))) {
                $a = new Admin($this->dbhr, $this->dbhm, $admin['id']);
                $done += $a->mailMembers($gently);
                $this->dbhm->preExec("UPDATE admins SET complete = NOW() WHERE id = ?;", [$admin['id']]);
            }

            if (file_exists('/tmp/iznik.mail.abort')) {
                exit(0);
            }
        }

        return($done);
    }

    public function mailMembers($gently, $userid = NULL) {
        $mailers = [];

        for ($i = 1; $i <= Admin::SPOOLERS; $i++) {
            # Don't split on Travis.
            list ($transport, $mailer) = Mail::getMailer('localhost',getenv('STANDALONE')  ? '/spool' : (Admin::SPOOLNAME . $i));
            $mailers[$i] = $mailer;
        }

        $done = 0;
        $groupid = $this->admin['groupid'];

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $atts = $g->getPublic();

        $groupname = $atts['namedisplay'];
        $userq = $userid ? " AND userid = $userid " : '';

        $sql = "SELECT userid FROM memberships WHERE groupid = ? $userq;";
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
                ]);

                if (count($sent) > 0) {
                    # We have - skip
                    #error_log("Already sent");
                    continue;
                }
            }

            $preferred = $u->getEmailPreferred();

            if ($preferred) {
                try {
                    $msg = $this->constructMessage($groupname, $preferred, $u->getName(), $g->getAutoEmail(), $this->admin['subject'], $this->admin['text']);

                    Mail::addHeaders($msg, Mail::ADMIN, $u->getId());

                    # Pick a random spooler.  This gives more throughput.
                    $mailer = $mailers[rand(1, Admin::SPOOLERS)];
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

                    if ($done % 1000 === 0 && $gently) {
                        Utils::checkFiles('/var/www/iznik/spool_admin_*', 30000, 1000);
                    }
                } catch (\Exception $e) {
                    error_log("Failed with " . $e->getMessage());
                }
            }
        }

        return($done);
    }

    public function updateEdit() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $this->dbhm->preExec("UPDATE admins SET editedat = NOW(), editedby = ? WHERE id = ?;", [
            $me->getId(),
            $this->id
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
        $ret = [];

        $admins = $this->dbhr->preQuery("SELECT admins.id, admins.groupid FROM admins INNER JOIN memberships ON memberships.groupid = admins.groupid WHERE memberships.userid = ? AND pending = 1 AND complete IS NULL AND role IN ('Moderator', 'Owner') ORDER BY created ASC;", [
            $userid
        ]);

        $u = new User($this->dbhr, $this->dbhm, $userid);

        foreach ($admins as $admin) {
            if ($u->activeModForGroup($admin['groupid'])) {
                $a = new Admin($this->dbhr, $this->dbhm, $admin['id']);
                $ret[] = $a->getPublic();
            }
        }

        return($ret);
    }

    public function hold() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $this->dbhm->preExec("UPDATE admins SET heldby = ?, heldat = NOW() WHERE id = ?;", [
            $me->getId(),
            $this->id
        ]);
    }

    public function release() {
        $this->dbhm->preExec("UPDATE admins SET heldby = NULL WHERE id = ?;", [
            $this->id
        ]);
    }

    public function delete() {
        $this->dbhm->preExec("DELETE FROM admins WHERE id = ?;", [
            $this->id
        ]);
    }
}

