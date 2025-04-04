<?php
namespace Freegle\Iznik;



class Bounce
{
    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;
    private $to, $msg;

    function __construct($dbhr, $dbhm, $id = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public function save($to, $msg) {
        $this->dbhm->preExec("INSERT INTO bounces (`to`, `msg`) VALUES (?, ?);", [
            $to,
            $msg
        ]);

        return($this->dbhm->lastInsertId());
    }

    public function ignore($code) {
        # Some bounces are basically temporary, and we shouldn't turn mail off because of them.
        $ret = FALSE;
        foreach ([
            'delivery temporarily suspended',
            'Trop de connexions',
            'found on industry URI blacklists',
            'This message has been blocked',
            'is listed'
                 ] as $err) {
            if (stripos($code, $err) !== FALSE) {
                $ret = TRUE;
            }
        }

        return($ret);
    }

    public function isPermanent($code) {
        $ret = FALSE;

        foreach ([
            '550 Requested action not taken: mailbox unavailable',
            'Invalid recipient',
            '550 5.1.1',
            '550-5.1.1',
            '550 No Such User Here',
            'dd This user doesn\'t have',
                 ] as $err) {
            if (stripos($code, $err) !== FALSE) {
                $ret = TRUE;
            }
        }

        return($ret);
    }

    public function process($id = NULL) {
        $ret = FALSE;
        $idq = $id ? " WHERE id = ? " : "";
        $bounces = $this->dbhr->preQuery("SELECT * FROM bounces $idq ORDER BY date ASC;", $id ? [ $id ] : [] );

        foreach ($bounces as $bounce) {
            if (preg_match('/^bounce-(.*)-/', $bounce['to'], $matches)) {
                $uid = $matches[1];
                $u = User::get($this->dbhr, $this->dbhm, $uid);

                if ($uid == $u->getId()) {
                    if (preg_match('/^Diagnostic-Code:(.*)$/im', $bounce['msg'], $matches)) {
                        $code = trim($matches[1]);

                        if (!$this->ignore($code)) {
                            if (preg_match('/^Original-Recipient:.*;(.*)$/im', $bounce['msg'], $matches)) {
                                $email = trim($matches[1]);

                                $r = $u->getIdForEmail($email);
                                $eid = $r ? $r['id'] : NULL;
                                $ueid = $r ? $r['userid'] : NULL;

                                if ($uid ==  $ueid) {
                                    # This email belongs to the claimed user.
                                    $this->log->log([
                                        'type' => Log::TYPE_USER,
                                        'subtype' => Log::SUBTYPE_BOUNCE,
                                        'user' => $uid,
                                        'text' => "Bounce for $email: $code"
                                    ]);

                                    $this->dbhm->preExec("INSERT INTO bounces_emails (emailid, reason, permanent) VALUES (?, ?, ?);", [
                                        $eid,
                                        $code,
                                        $this->isPermanent($code)
                                    ]);

                                    $this->dbhm->preExec("UPDATE users_emails SET bounced = NOW() WHERE email LIKE ?;", [
                                        $email
                                    ]);

                                    $ret = TRUE;
                                }
                            }
                        }
                    }
                }
            }

            $this->dbhm->preExec("DELETE FROM bounces WHERE id = ?;", [ $bounce['id'] ]);
        }

        $hour = intval(date('H'));

        if (!$id && $hour < 4) {
            # Shrink the table.  Don't do this while the backup might be running, as ALTER TABLE is a DDL op
            # which would interrupt the backup.  Also don't do it except during the night, as it might block
            # cluster replication causing a site stall.
            $this->dbhm->preExec("ALTER TABLE bounces engine=InnoDB;");
        }

        return($ret);
    }

    private function suspend($id) {
        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_SUSPEND_MAIL,
            'user' => $id
        ]);

        error_log("...suspend mail to $id");

        $this->dbhm->preExec("UPDATE users SET bouncing = 1 WHERE id = ?;", [  $id ]);
    }

    public function suspendMail($id = NULL, $permthreshold = 3, $allthreshold = 50) {
        $idq = $id ? " AND userid = $id " : "";
        $users = $this->dbhr->preQuery("SELECT COUNT(*) AS count, userid, emailid, email, reason FROM bounces_emails INNER JOIN users_emails ON users_emails.id = bounces_emails.emailid INNER JOIN users ON users.id = users_emails.userid $idq AND users.bouncing = 0 WHERE permanent = 1 AND reset = 0 GROUP BY userid ORDER BY count DESC;");

        # For both perm and temp bounces we only want to suspend the user if the bounces refer to the preferred email.
        # But for some legacy users we might not have users_email.preferred = 1 entry, so we can't use that - instead
        # get the preferred email and compare it.
        foreach ($users as $user) {
            $u = new User($this->dbhr, $this->dbhm, $user['userid']);

            if ($user['count'] >= $permthreshold && $user['email'] == $u->getEmailPreferred()) {
                $this->suspend($user['userid']);
            }
        }

        $users = $this->dbhr->preQuery("SELECT COUNT(*) AS count, userid, emailid, email, reason FROM bounces_emails INNER JOIN users_emails ON users_emails.id = bounces_emails.emailid INNER JOIN users ON users.id = users_emails.userid $idq AND users.bouncing = 0 WHERE reset = 0 GROUP BY userid ORDER BY count DESC;");

        foreach ($users as $user) {
            $u = new User($this->dbhr, $this->dbhm, $user['userid']);

            if ($user['count'] >= $allthreshold && $user['email'] == $u->getEmailPreferred()) {
                $this->suspend($user['userid']);
            }
        }
    }
}