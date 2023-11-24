<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/relevant/nearby.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');

class Nearby
{
    /** @var  $dbhr LoggedPDO */
    var $dbhr;
    /** @var  $dbhm LoggedPDO */
    var $dbhm;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    # Split out for UT to override
    public function sendOne($mailer, $message) {
        $mailer->send($message);
    }

    public function messages($groupid) {
        list ($transport, $mailer) = Mail::getMailer();
        $count = 0;

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);

        if ($g->getSetting('relevant', 1)) {
            # Find the recent extant messages
            $sql = "SELECT DISTINCT messages_spatial.msgid AS id, messages_spatial.msgtype AS type, ST_Y(messages_spatial.point) AS lat, ST_X(messages_spatial.point) AS lng, messages.fromuser, messages.subject FROM messages_spatial INNER JOIN messages ON messages_spatial.msgid = messages.id WHERE groupid = ? AND messages.fromuser IS NOT NULL AND messages_spatial.successful = 0;";
            $msgs = $this->dbhr->preQuery($sql, [ $groupid ] );
            #error_log("Look for extant messages for $groupid found " . count($msgs));

            foreach ($msgs as $msg) {
                # We have a message which is still extant and where we know the location.  Find nearby
                # users we've not mailed about this message.
                $mlat = $msg['lat'];
                $mlng = $msg['lng'];

                # Find a bounding box roughly 4km.  Later we'll restrict that to 2 miles.
                $dist = 4000;
                $ne = \GreatCircle::getPositionByDistance($dist, 45, $mlat, $mlng);
                $sw = \GreatCircle::getPositionByDistance($dist, 225, $mlat, $mlng);
                $box = "ST_GeomFromText('POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))', {$this->dbhr->SRID()})";

                $users = $this->dbhr->preQuery("SELECT userid AS id FROM users_approxlocs WHERE ST_Contains($box, position) AND userid != ?;", [
                    $msg['fromuser']
                ]);
                #error_log("Look for users near $mlat, $mlng with $box found " . count($users));
                $mu = NULL;

                foreach ($users as $user) {
                    if (!$mu) {
                        $mu = User::get($this->dbhr, $this->dbhm, $msg['fromuser']);
                        $mname = $mu->getName();
                    }

                    $u = User::get($this->dbhr, $this->dbhm, $user['id']);

                    # Only send these to people who are on at least one group where these mails are allowed.
                    if ($u->getPrivate('relevantallowed') && $u->sendOurMails()) {
                        $onone = FALSE;
                        $membs = $u->getMemberships();

                        foreach  ($membs as $memb) {
                            $g = Group::get($this->dbhr, $this->dbhm, $memb['id']);

                            if ($g->getSetting('engagement', TRUE)) {
                                $onone = TRUE;
                            }
                        }

                        if ($onone) {
                            $miles = $u->getDistance($msg['lat'], $msg['lng']);
                            $miles = round($miles);

                            # We mail the most nearby people - but too far it's probably not worth it.
                            if ($miles <= 2) {
                                # Check we've not mailed them recently.
                                $mailed = $this->dbhr->preQuery(
                                    "SELECT MAX(timestamp) AS max FROM users_nearby WHERE userid = ?;",
                                    [
                                        $user['id']
                                    ]
                                );

                                # ..or about this message.
                                $thisone = $this->dbhr->preQuery("SELECT * FROM users_nearby WHERE userid = ? AND msgid = ?;", [
                                    $user['id'],
                                    $msg['id']
                                ]);

                                if (count($thisone) == 0 && (count($mailed) == 0 || (time() - strtotime($mailed[0]['max']) > 7 * 24 * 60 * 60))) {
                                    $this->dbhm->preExec(
                                        "INSERT INTO users_nearby (userid, msgid) VALUES (?, ?);",
                                        [
                                            $user['id'],
                                            $msg['id']
                                        ]
                                    );

                                    $subj = "Could you help $mname ($miles mile" . ($miles != 1 ? 's' : '') . " away)?";
                                    $noemail = 'relevantoff-' . $user['id'] . "@" . USER_DOMAIN;
                                    $textbody = "$mname, who's about $miles mile" . ($miles != 1 ? 's' : '') . " miles from you, has posted " . $msg['subject'] . ".  Do you know anyone who can help?  The post is here: https://" . USER_SITE . "/message/{$msg['id']}?src=nearby\r\nIf you don't want to get these suggestions, mail $noemail.";

                                    $email = $u->getEmailPreferred();
                                    $html = relevant_nearby(
                                        USER_SITE,
                                        USERLOGO,
                                        $mname,
                                        $miles,
                                        $msg['subject'],
                                        $msg['id'],
                                        $msg['type'],
                                        $email,
                                        $noemail
                                    );

                                    try {
                                        $message = \Swift_Message::newInstance()
                                            ->setSubject($subj)
                                            ->setFrom([NOREPLY_ADDR => SITE_NAME])
                                            ->setReturnPath($u->getBounce())
                                            ->setTo([$email => $u->getName()])
                                            ->setBody($textbody);

                                        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                        # Outlook.
                                        $htmlPart = \Swift_MimePart::newInstance();
                                        $htmlPart->setCharset('utf-8');
                                        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                        $htmlPart->setContentType('text/html');
                                        $htmlPart->setBody($html);
                                        $message->attach($htmlPart);

                                        Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::NEARBY, $u->getId());

                                        $this->sendOne($mailer, $message);
                                        error_log("...user {$user['id']} dist $miles");
                                        $count++;
                                    } catch (\Exception $e) {
                                        error_log("Send to $email failed with " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }

                # Prod garbage collection, as we've seen high memory usage by this.
                User::clearCache();
                gc_collect_cycles();
            }
        }

        Group::clearCache();

        return($count);
    }

    public function updateLocations() {
        $mysqltime =  date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE + 24 * 60 * 60)));
        $users = $this->dbhr->preQuery("SELECT DISTINCT users.id, users.lastaccess FROM users INNER JOIN memberships ON memberships.userid = users.id WHERE users.lastaccess >= '$mysqltime';");
        $count = 0;
        $total = count($users);

        foreach ($users as $user) {
            $u = new User($this->dbhr, $this->dbhm, $user['id']);

            # Get approximate location where we have one.
            list($lat, $lng, $loc) = $u->getLatLng(FALSE, FALSE, Utils::BLUR_USER);

            if ($lat || $lng) {
                # We found one.
                $this->dbhm->preExec("INSERT INTO users_approxlocs (userid, lat, lng, position, timestamp) VALUES (?, ?, ?, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), {$this->dbhr->SRID()}), ?) ON DUPLICATE KEY UPDATE lat = ?, lng = ?, position = ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), {$this->dbhr->SRID()}), timestamp = ?;", [
                    $user['id'],
                    $lat,
                    $lng,
                    $lng,
                    $lat,
                    $user['lastaccess'],
                    $lat,
                    $lng,
                    $lng,
                    $lat,
                    $user['lastaccess']
                ]);
            }

            $count++;

            if ($count % 1000 == 0) {
                error_log("...$count / $total");
            }
        }

        $this->dbhm->preExec("DELETE FROM users_approxlocs WHERE timestamp < ?", [
            $mysqltime
        ]);
    }

    public function getUsersNear($lat, $lng, $mods) {
        $ret = [];

        if ($mods) {
            $users = $this->dbhr->preQuery("SELECT userid, firstname, lastname, fullname, lat, lng, settings FROM users_approxlocs INNER JOIN users ON users.id = users_approxlocs.userid WHERE users.systemrole IN (?, ?, ?);", [
                User::SYSTEMROLE_MODERATOR,
                User::SYSTEMROLE_SUPPORT,
                User::SYSTEMROLE_ADMIN
            ]);

            foreach ($users as $user) {
                $name = NULL;

                if (Utils::pres('fullname', $user)) {
                    $name = $user['fullname'];
                } else if (Utils::pres('firstname', $user) || Utils::pres('lastname', $user)) {
                    $first = Utils::pres('firstname', $user);
                    $last = Utils::pres('lastname', $user);

                    $name = $first && $last ? "$first $last" : ($first ? $first : $last);
                }

                if ($name) {
                    $name = User::removeTNGroup($name);

                    $ret[$user['userid']] = $user;
                    $ret[$user['userid']]['displayname'] = $name;

                    # Need decoded settings to get the profile.
                    $ret[$user['userid']]['settings'] = json_decode(Utils::presdef('settings', $user, '[]'), TRUE);
                }
            }

            $u = new User($this->dbhr, $this->dbhm);
            $u->getPublicProfiles($ret, []);

            foreach ($ret as $userid => $r) {
                $ret[$userid]['settings'] = NULL;
            }
        }

        return array_values($ret);
    }
}
