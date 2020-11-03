<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/relevant/nearby.php');

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
            $mysqltime = date ("Y-m-d", strtotime(MessageCollection::RECENTPOSTS));
            $sql = "SELECT DISTINCT messages.id, messages.type FROM messages LEFT OUTER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND collection = 'Approved' INNER JOIN groups ON groups.id = messages_groups.groupid AND groups.id = ? WHERE messages_outcomes.msgid IS NULL AND messages.type IN ('Offer', 'Wanted') AND messages.arrival > '$mysqltime' LIMIT 1000;";
            $msgs = $this->dbhr->preQuery($sql, [ $groupid ] );

            foreach ($msgs as $msg) {
                $m = new Message($this->dbhr, $this->dbhm, $msg['id']);
                $lid = $m->getPrivate('locationid');
                $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
                $name = $u->getName();

                # Can't use hasOutcome() here because the message might have expired, and we don't want to mail
                # people about expired messages.
                $atts = $m->getPublic(FALSE, FALSE);

                if ($lid && !count($atts['outcomes']) && !$u->getPrivate('deleted') && !$m->getPrivate('deleted')) {
                    $l = new Location($this->dbhr, $this->dbhm, $lid);
                    $lat = $l->getPrivate('lat');
                    $lng = $l->getPrivate('lng');

                    if ($lat && $lng && $m->getFromuser()) {
                        # We have a message which is still extant and where we know the location.  Find nearby
                        # users we've not mailed about this message.
                        error_log("{$msg['id']} " . $m->getPrivate('subject') . " at $lat, $lng");
                        $sql = "SELECT users.id, locations.lat, locations.lng, haversine($lat, $lng, locations.lat, locations.lng) AS dist FROM users INNER JOIN memberships ON users.id = memberships.userid INNER JOIN locations ON locations.id = users.lastlocation LEFT JOIN users_nearby ON users_nearby.userid = users.id AND users_nearby.msgid = {$msg['id']} WHERE groupid = $groupid AND users.id != " . $m->getFromuser() . " AND users_nearby.msgid IS NULL ORDER BY dist ASC LIMIT 100;";
                        $users = $this->dbhr->preQuery($sql);

                        foreach ($users as $user) {
                            $u2 = new User($this->dbhr, $this->dbhm, $user['id']);

                            if ($u2->getPrivate('relevantallowed') && $u2->sendOurMails()) {
                                $miles = $u2->getDistance($lat, $lng);
                                $miles = round($miles);

                                # We mail the most nearby people - but too far it's probably not worth it.
                                if ($miles <= 2) {
                                    # Check we've not mailed them recently.
                                    $mailed = $this->dbhr->preQuery("SELECT MAX(timestamp) AS max FROM users_nearby WHERE userid = ?;", [
                                        $user['id']
                                    ]);

                                    if (count($mailed) == 0 || (time() - strtotime($mailed[0]['max']) > 7 * 24 * 60 * 60)) {
                                        $this->dbhm->preExec("INSERT INTO users_nearby (userid, msgid) VALUES (?, ?);", [
                                            $user['id'],
                                            $msg['id']
                                        ]);

                                        $subj = "Could you help " . $u->getName() . " ($miles mile" . ($miles != 1 ? 's' : '') . " away)?";
                                        $noemail = 'relevantoff-' . $user['id'] . "@" . USER_DOMAIN;
                                        $textbody = "$name, who's about $miles mile" . ($miles != 1 ? 's' : '') . " miles from you, has posted " . $m->getSubject() . ".  Do you know anyone who can help?  The post is here: https://" . USER_SITE . "/message/{$msg['id']}?src=nearby\r\nIf you don't want to get these suggestions, mail $noemail.";

                                        $email = $u2->getEmailPreferred();
                                        $html = relevant_nearby(USER_SITE, USERLOGO, $name, $miles, $m->getSubject(), $msg['id'], $msg['type'], $email, $noemail);

                                        try {
                                            $message = \Swift_Message::newInstance()
                                                ->setSubject($subj)
                                                ->setFrom([NOREPLY_ADDR => SITE_NAME ])
                                                ->setReturnPath($u->getBounce())
                                                ->setTo([ $email => $u2->getName() ])
                                                ->setBody($textbody);

                                            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                            # Outlook.
                                            $htmlPart = \Swift_MimePart::newInstance();
                                            $htmlPart->setCharset('utf-8');
                                            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                            $htmlPart->setContentType('text/html');
                                            $htmlPart->setBody($html);
                                            $message->attach($htmlPart);

                                            Mail::addHeaders($message, Mail::NEARBY, $u2->getId());

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
                }
            }
        }

        return($count);
    }

    public function updateLocations($modonly = TRUE, $since = "Midnight 90 days ago") {
        $mysqltime = date("Y-m-d", strtotime($since));
        $mods = $this->dbhr->preQuery("SELECT DISTINCT users.id FROM users INNER JOIN memberships ON memberships.userid = users.id WHERE users.lastaccess >= '$mysqltime' AND memberships.role IN (?, ?);", [
            User::ROLE_MODERATOR,
            User::ROLE_OWNER
        ]);

        foreach ($mods as $mod) {
            $u = new User($this->dbhr, $this->dbhm, $mod['id']);

            # Get approximate location where we have one.
            list($lat, $lng, $loc) = $u->getLatLng(FALSE, FALSE, User::BLUR_1K);

            if ($lat || $lng) {
                # We found one.
                $this->dbhm->preExec("INSERT INTO users_approxlocs (userid, lat, lng, position) VALUES (?, ?, ?, GEOMFROMTEXT(CONCAT('POINT(', ?, ' ', ?, ')'))) ON DUPLICATE KEY UPDATE lat = ?, lng = ?, position = GEOMFROMTEXT(CONCAT('POINT(', ?, ' ', ?, ')'));", [
                    $mod['id'],
                    $lat,
                    $lng,
                    $lng,
                    $lat,
                    $lat,
                    $lng,
                    $lng,
                    $lat
                ]);
            }
        }
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
                    $ret[$user['userid']] = $user;
                    $ret[$user['userid']]['displayname'] = $name;

                    # Need decoded settings to get the profile.
                    $ret[$user['userid']]['settings'] = json_decode(Utils::presdef('settings', $user, '[]'), TRUE);
                }
            }

            $u = new User($this->dbhr, $this->dbhm);
            $u->getPublicProfiles($ret);

            foreach ($ret as $userid => $r) {
                $ret[$userid]['settings'] = NULL;
            }
        }

        return array_values($ret);
    }
}
