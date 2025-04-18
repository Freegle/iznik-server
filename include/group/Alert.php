<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/alert.php');

class Alert extends Entity
{
    const MODS = 'Mods';
    const USERS = 'Users';
    
    const TYPE_MODEMAIL = 'ModEmail';
    const TYPE_OWNEREMAIL = 'OwnerEmail';

    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'groupid', 'from', 'to', 'created', 'groupprogress', 'complete', 'subject',
        'text', 'html');

    /** @var  $log Log */
    private $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'alerts', 'alert', $this->publicatts);
        $this->log = new Log($dbhr, $dbhm);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function create($groupid, $from, $to, $subject, $text, $html, $askclick, $tryhard) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO alerts (`groupid`, `from`, `to`, `subject`, `text`, `html`, `askclick`, `tryhard`) VALUES (?,?,?,?,?,?,?,?);", [
            $groupid, $from, $to, $subject, $text, $html, $askclick, $tryhard
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();
            $this->fetch($this->dbhm, $this->dbhm, $id, 'alerts', 'alert', $this->publicatts);
        }

        return($id);
    }

    public function getList() {
        $sql = "SELECT id FROM alerts ORDER BY id DESC LIMIT 20;";
        $alerts = $this->dbhr->preQuery($sql);
        $ret = [];
        foreach ($alerts as $alert) {
            $a = new Alert($this->dbhr, $this->dbhm, $alert['id']);
            $thisone = $a->getPublic();
            $thisone['created'] = Utils::ISODate($thisone['created']);
            $thisone['complete'] = Utils::ISODate($thisone['complete']);
            $thisone['stats'] = $a->getStats();

            if ($thisone['groupid']) {
                $g = Group::get($this->dbhr, $this->dbhm, $thisone['groupid']);
                $thisone['group'] = $g->getPublic();
                $thisone['group']['settings'] = NULL;
                unset($thisone['groupid']);
            }
            $ret[] = $thisone;
        }

        return($ret);
    }

    public function constructMessage($to, $toname, $touid, $from, $subject, $text, $html) {
        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom([$from])
            ->setTo([$to => $toname])
            ->setBody($text);

        if ($touid) {
            $message->setReturnPath("bounce-$touid-" . time() . "@" . USER_DOMAIN);
        }

        if ($html && strlen($html) > 10) {
            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
            # Outlook.
            $htmlPart = \Swift_MimePart::newInstance();
            $htmlPart->setCharset('utf-8');
            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
            $htmlPart->setContentType('text/html');
            $htmlPart->setBody($html);
            $message->attach($htmlPart);
        }

        return($message);
    }

    public function beacon($id) {
        # Don't overwrite a Clicked response with a read one.
        $this->dbhm->preExec("UPDATE alerts_tracking SET responded = NOW(), response = 'Read' WHERE id = ? AND response IS NULL;", [ $id] );
    }

    public function clicked($id) {
        $this->dbhm->preExec("UPDATE alerts_tracking SET responded = NOW(), response = 'Clicked' WHERE id = ?;", [ $id] );
    }

    public function process($id = NULL, $type = Group::GROUP_FREEGLE) {
        $done = 0;
        $idq = $id ? " id = $id AND " : '';
        $sql = "SELECT * FROM alerts WHERE $idq complete IS NULL;";
        $alerts = $this->dbhr->preQuery($sql);

        foreach ($alerts as $alert) {
            $a = new Alert($this->dbhr, $this->dbhm, $alert['id']);

            # This alert might be for a specific group, or all Freegle groups.  We only process a single group in this
            # pass.  If it's for multiple, we'll update the progress and do the next one next time.
            $groupid = $a->getPrivate('groupid');
            $groupq =  $groupid ? " WHERE id = $groupid " : (" WHERE `type` = '$type' AND id > {$alert['groupprogress']} AND publish = 1 ORDER BY id ASC LIMIT 1");

            $groups = $this->dbhr->preQuery("SELECT id, nameshort FROM `groups` $groupq;");
            $complete = count($groups) == 0;
            #error_log("Count " . count($groups) . " done $done");

            foreach ($groups as $group) {
                #error_log("...{$alert['id']} -> {$group['nameshort']}");
                $done += $a->mailMods($alert['id'], $group['id'], $alert['tryhard'], $groupid != NULL, !$a->getPrivate('groupid'));

                if ($groupid) {
                    # This is to a specific group.  We are now done.
                    #error_log("Specific group $groupid");
                    $complete = TRUE;
                } else {
                    # This is for multiple groups.
                    $this->dbhm->preExec("UPDATE alerts SET groupprogress = ? WHERE id = ?;", [
                        $group['id'],
                        $alert['id']
                    ]);
                }
            }

            if ($complete) {
                $this->dbhm->preExec("UPDATE alerts SET complete = NOW() WHERE id = ?;", [ $alert['id'] ]);
            }
        }

        return($done);
    }

    public function getFrom() {
        $from = NULL;
        
        switch ($this->alert['from']) {
            case 'support': $from = SUPPORT_ADDR; break;
            case 'info': $from = INFO_ADDR; break;
            case 'geeks': $from = GEEKS_ADDR; break;
            case 'board': $from = BOARD_ADDR; break;
            case 'mentors': $from = MENTORS_ADDR; break;
            case 'newgroups': $from = NEWGROUPS_ADDR; break;
            case 'ro': $from = RO_ADDR; break;
            case 'volunteers': $from = VOLUNTEERS_ADDR; break;
            case 'centralmods': $from = CENTRALMODS_ADDR; break;
            case 'councils': $from = COUNCILS_ADDR; break;
        }

        return($from);
    }

    public function getStats() {
        $ret = [
            'sent' => [],
            'responses' => [
                'groups' => [],
                'mods' => [],
                'modemails' => [],
                'owner' => []
            ]
        ];

        $ret['sent']['mods'] = count($this->dbhr->preQuery("SELECT DISTINCT userid FROM alerts_tracking WHERE alertid = ? AND `type` = ?;", [
            $this->id,
            Alert::TYPE_MODEMAIL
        ]));

        $ret['sent']['modemails'] = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM alerts_tracking WHERE alertid = ? AND `type` = ?;", [ 
            $this->id,
            Alert::TYPE_MODEMAIL
        ])[0]['count'];
        
        $ret['sent']['owneremails'] = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM alerts_tracking WHERE alertid = ? AND `type` = ?;", [
            $this->id,
            Alert::TYPE_OWNEREMAIL
        ])[0]['count'];
        
        $ret['responses']['mods']['none'] = count($this->dbhr->preQuery("SELECT DISTINCT userid FROM alerts_tracking WHERE alertid = ? AND `type` = ? AND response IS NULL;", [
            $this->id,
            Alert::TYPE_MODEMAIL
        ]));

        $ret['responses']['mods']['reached'] = count($this->dbhr->preQuery("SELECT DISTINCT userid FROM alerts_tracking WHERE alertid = ? AND `type` = ? AND response IN ('Read', 'Clicked');", [
            $this->id,
            Alert::TYPE_MODEMAIL
        ]));

        # Owner.  It looks like Yahoo fetches our beacon, so only count clicks.
        $ret['responses']['owner']['none'] = count($this->dbhr->preQuery("SELECT * FROM alerts_tracking WHERE alertid = ? AND `type` = ? AND (response IS NULL OR response = 'Read');", [
            $this->id,
            Alert::TYPE_OWNEREMAIL
        ]));

        $ret['responses']['owner']['reached'] = count($this->dbhr->preQuery("SELECT * FROM alerts_tracking WHERE alertid = ? AND `type` = ? AND response IN ('Clicked');", [
            $this->id,
            Alert::TYPE_OWNEREMAIL
        ]));

        $sql = "SELECT DISTINCT groupid FROM alerts_tracking WHERE alertid = ?;";
        $groups = $this->dbhr->preQuery($sql, [ $this->id ]);

        foreach ($groups as $group) {
            # We have reached a group if we've had a click on an owner, or a read/click on a mod.
            #
            # TODO If we send to a user on one group who reads it, and who is a mod on that group, should we count the
            # second group as having been reached?
            $sql = "SELECT COUNT(*) AS count, CASE WHEN ((response = 'Clicked') OR (response = 'Read' AND `type` = 'ModEmail')) THEN 'Reached' ELSE 'None' END AS rsp FROM alerts_tracking WHERE alertid = ? AND groupid = ? GROUP BY rsp;";
            $data = $this->dbhr->preQuery($sql, [ $this->id, $group['groupid'] ]);
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $ret['responses']['groups'][] = [
                'group' => $g->getPublic(),
                'summary' => $data
            ];
        }

        return($ret);
    }

    public function mailMods($alertid, $groupid, $tryhard = TRUE, $cc = FALSE, $global = FALSE) {
        list ($transport, $mailer) = Mail::getMailer();
        $done = 0;

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $from = $this->getFrom();

        # Mail the mods individually.  We only want to mail each emailid once per alert, otherwise it's horrible for
        # people on many groups.
        $sql = "SELECT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');";
        $mods = $this->dbhr->preQuery($sql, [ $groupid ]);
        error_log("..." . count($mods) . " volunteers");

        foreach ($mods as $mod) {
            $u = User::get($this->dbhr, $this->dbhm, $mod['userid']);

            # Get emails excluding bouncing.
            $emails = $u->getEmails(FALSE, TRUE);

            foreach ($emails as $email) {
                try {
                    error_log("check {$email['email']} real " . Mail::realEmail($email['email']));

                    if (Mail::realEmail($email['email'])) {
                        # Check if we have already mailed them.
                        $sql = "SELECT id, response FROM alerts_tracking WHERE userid = ? AND alertid = ? AND emailid = ?;";
                        $previous = $this->dbhr->preQuery($sql, [ $mod['userid'], $this->id, $email['id']]);
                        $gotprevious = count($previous) > 0;

                        # Record for tracking that we have processed this group.
                        $this->dbhm->preExec("INSERT INTO alerts_tracking (alertid, groupid, userid, emailid, `type`) VALUES (?,?,?,?,?);",
                            [
                                $this->id,
                                $groupid,
                                $mod['userid'],
                                $email['id'],
                                Alert::TYPE_MODEMAIL
                            ]
                        );

                        if (!$gotprevious) {
                            # We don't want to send to a personal email if they've already been mailed at that email - even
                            # if it was on another group.  This is because some people are on many groups, with many emails,
                            # and this can flood them.  They may get a copy via the owner address, though.
                            $trackid = $this->dbhm->lastInsertId();
                            $html = alert_tpl(
                                $g->getPrivate('nameshort'),
                                $u->getName(),
                                USER_SITE,
                                USERLOGO,
                                $this->alert['subject'],
                                $this->alert['html'] ? $this->alert['html'] : nl2br($this->alert['text']),
                                NULL, # Should be $u->getUnsubLink(USER_SITE, $mod['userid']) once we go live TODO ,
                                $this->alert['askclick'] ? 'https://' . MOD_SITE . "/alert/viewed/$trackid" : NULL,
                                'https://' . MOD_SITE . "/beacon/$trackid",
                                $global);

                            $text = $this->alert['text'];
                            if ($this->alert['askclick']) {
                                $text .=  "\r\n\r\nPlease click to confirm you got this:\r\n\r\n" .
                                    'https://' . USER_SITE . "/alert/viewed/$trackid";
                            }

                            $msg = $this->constructMessage($email['email'], $u->getName(), $u->getId(), $from, $this->alert['subject'], $text, $html);
                            Mail::addHeaders($this->dbhr, $this->dbhm, $msg, Mail::ALERT, $u->getId());

                            $mailer->send($msg);
                            $done++;
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Failed with " . $e->getMessage());
                }
            }
        }

        if ($g->getPrivate('contactmail')) {
            try {
                # Mail the contact address.
                $this->dbhm->preExec("INSERT INTO alerts_tracking (alertid, groupid, `type`) VALUES (?,?,?);",
                    [
                        $this->id,
                        $groupid,
                        Alert::TYPE_OWNEREMAIL
                    ]
                );

                $trackid = $this->dbhm->lastInsertId();
                $toname = $g->getPrivate('nameshort') . " volunteers";
                $html = alert_tpl(
                    $g->getPrivate('nameshort'),
                    $toname,
                    USER_SITE,
                    USERLOGO,
                    $this->alert['subject'],
                    $this->alert['html'],
                    NULL,
                    $this->alert['askclick'] ? 'https://' . USER_SITE . "/alert/viewed/$trackid" : NULL,
                    'https://' . USER_SITE . "/beacon/$trackid",
                    $global);

                $text = $this->alert['text'];
                if ($this->alert['askclick']) {
                    $text .=  "\r\n\r\nPlease click to confirm you got this:\r\n\r\n" .
                        'https://' . USER_SITE . "/alert/viewed/$trackid";
                }

                $msg = $this->constructMessage($g->getPrivate('contactmail'), $toname, NULL, $from, $this->alert['subject'], $text, $html);
                Mail::addHeaders($this->dbhr, $this->dbhm, $msg, Mail::ALERT);
                $mailer->send($msg);
                $done++;
            } catch (\Exception $e) {
                error_log("Contact mail failed with " . $e->getMessage());
            }
        }

        if ($cc) {
            $toname = $g->getPrivate('nameshort') . " volunteers";
            $html = alert_tpl(
                $g->getPrivate('nameshort'),
                $toname,
                USER_SITE,
                USERLOGO,
                $this->alert['subject'],
                $this->alert['html'] ? $this->alert['html'] : nl2br($this->alert['text']),
                NULL,
                FALSE,
                'https://' . USER_SITE,
                $global);

            $text = $this->alert['text'];
            $msg = $this->constructMessage($from, $g->getPrivate('nameshort'), NULL, $from, $this->alert['subject'], $text, $html);
            Mail::addHeaders($this->dbhr, $this->dbhm, $msg, Mail::ALERT);
            $mailer->send($msg);
        }

        return($done);
    }
}

