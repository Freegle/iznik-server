<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/user/User.php');

class Engage
{
    private $dbhr, $dbhm;

    const USER_INACTIVE = 365 * 24 * 60 * 60 / 2;

    const FILTER_DONORS = 'Donors';
    const FILTER_INACTIVE = 'Inactive';

    const ATTEMPT_MISSING = 'Missing';
    const ATTEMPT_INACTIVE = 'Inactive';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/engage');
        $this->twig = new Twig_Environment($loader);
    }

    # Split out for UT to override
    public function sendOne($mailer, $message) {
        Mail::addHeaders($message, Mail::MISSING);
        $mailer->send($message);
    }

    public function findUsers($id = NULL, $filter) {
        $userids = [];

        switch ($filter) {
            case Engage::FILTER_DONORS: {
                # Find people who have donated in the last year, who have not been active in the last two months.
                $donatedsince = date("Y-m-d", strtotime("3 years ago"));
                $activesince = date("Y-m-d", strtotime("2 months ago"));
                $lastengage = date("Y-m-d", strtotime("1 month ago"));
                $uq = $id ? " AND users_donations.userid = $id " : "";
                $sql = "SELECT DISTINCT users_donations.userid, lastaccess FROM users_donations INNER JOIN users ON users.id = users_donations.userid LEFT JOIN engage ON engage.userid = users.id WHERE users_donations.timestamp >= ? AND users.lastaccess <= ? AND (engage.timestamp IS NULL OR engage.timestamp < ?) $uq;";
                $users = $this->dbhr->preQuery($sql, [
                    $donatedsince,
                    $activesince,
                    $lastengage
                ]);

                $userids = array_column($users, 'userid');
                break;
            }

            case Engage::FILTER_INACTIVE: {
                # Find people who we'll stop sending mails to soon.  This time is related to sendOurMails in User.
                $activeon = date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE + 7 * 24 * 60 * 60)));
                $uq = $id ? " AND users.id = $id " : "";
                $sql = "SELECT id FROM users WHERE DATE(lastaccess) = ? $uq;";
                $users = $this->dbhr->preQuery($sql, [
                    $activeon
                ]);

                $userids = array_column($users, 'id');
                break;
            }
        }

        return $userids;
    }

    public function sendUsers($attempt, $uids, $subject, $textbody, $template) {
        $count = 0;

        foreach ($uids as $uid) {
            $u = new User($this->dbhr, $this->dbhm, $uid);

            # Only send to users who have a membership, and who haven't disabled.
            try {
                $membs = $u->getMemberships(FALSE, Group::GROUP_FREEGLE);

                if (count($membs)) {
                    list ($transport, $mailer) = getMailer();
                    $m = Swift_Message::newInstance()
                        ->setSubject($subject)
                        ->setFrom([NOREPLY_ADDR => SITE_NAME])
                        ->setReplyTo(NOREPLY_ADDR)
                        ->setTo($u->getEmailPreferred())
//                        ->setTo('log@ehibbert.org.uk')
                        ->setBody($textbody);

                    Mail::addHeaders($m, Mail::MISSING);

                    $html = $this->twig->render($template, [
                        'name' => $u->getName(),
                        'email' => $u->getEmailPreferred(),
                        'subject' => $subject,
                        'unsubscribe' => $u->loginLink(USER_SITE, $u->getId(), "/unsubscribe", NULL)
                    ]);

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($html);
                    $m->attach($htmlPart);

                    $this->sendOne($mailer, $m);
                    $count++;

                    $this->recordEngage($uid, $attempt);
                }
            } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };
        }

        return $count;
    }

    public function recordEngage($userid, $attempt) {
        $this->dbhm->preExec("INSERT INTO engage (userid, type, timestamp) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE timestmp = NOW();", [
            $userid,
            $attempt
        ]);
    }

    public function checkSuccess($id = NULL) {
        $since = date("Y-m-d", strtotime("1 month ago"));
        $uq = $id ? " AND engage.userid = $id " : "";
        $sql = "SELECT engage.id, userid, lastaccess FROM engage INNER JOIN users ON users.id = engage.userid WHERE engage.timestamp >= ? AND engage.timestamp <= users.lastaccess AND succeeded IS NULL $uq;";
        $users = $this->dbhr->preQuery($sql, [
            $since
        ]);

        $count = 0;

        foreach ($users as $user) {
            $count++;
            $this->dbhm->preExec("UPDATE engage SET succeeded = ? WHERE id = ?;", [
                $user['lastaccess'],
                $user['id']
            ]);
        }

        return $count;
    }
}
