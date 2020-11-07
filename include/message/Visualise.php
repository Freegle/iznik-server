<?php
namespace Freegle\Iznik;



class Visualise extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'msgid', 'attid', 'fromuser', 'touser', 'fromlat', 'fromlng', 'tolat', 'tolng', 'distance', 'timestamp');

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'visualise', 'visualise', $this->publicatts);
    }

    public function create($msgid, $attid, $timestamp, $fromuser, $touser, $flat, $flng, $tlat, $tlng) {
        $ret = NULL;

        $f = new POI($flat, $flng);
        $t = new POI($tlat, $tlng);
        $metres = round($f->getDistanceInMetersTo($t));

        if ($metres < 30000) {
            $this->dbhm->preExec("INSERT IGNORE INTO visualise (msgid, attid, timestamp, fromuser, touser, fromlat, fromlng, tolat, tolng, distance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?);", [
                $msgid,
                $attid,
                $timestamp,
                $fromuser,
                $touser,
                $flat,
                $flng,
                $tlat,
                $tlng,
                $metres
            ]);
        }

        $id = $this->dbhm->lastInsertId();

        return($id);
    }
    
    public function scanMessages($ago = "midnight 7 days ago") {
        $mysqltime = date("Y-m-d H:i:s", strtotime($ago));

        # Find messages which are known to have been taken, with photos.
        $msgs = $this->dbhr->preQuery("SELECT DISTINCT messages.id, a.id AS attid, messages.fromuser, messages_by.userid, messages.subject, messages_by.timestamp FROM messages INNER JOIN messages_by ON messages.id = messages_by.msgid INNER JOIN messages_attachments a ON messages.id = a.msgid WHERE messages_by.timestamp > ? AND messages.type = ? AND userid IS NOT NULL ORDER BY messages_by.timestamp DESC;", [
            $mysqltime,
            Message::TYPE_OFFER
        ]);

        foreach ($msgs as $msg) {
            error_log("#{$msg['id']}");
            $tlid = NULL;
            $flid = NULL;
            $ok = TRUE;

            $fu = new User($this->dbhr, $this->dbhm, $msg['fromuser']);
            $s = $fu->getPrivate('settings');

            if ($s) {
                $settings = json_decode($s, TRUE);
                if (array_key_exists('useprofile', $settings) && !$settings['useprofile']) {
                    # Giver doesn't want their profile pic visible.  They're probably sensitive about
                    # privacy, so even though we could show their approx location and default profile
                    # in accordance with our privacy profile, skip this message.  We don't need every
                    # last message to produce something useful.
                    $ok = FALSE;
                }
            }

            $ctx = NULL;
            $atts = $fu->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
            $fu->ensureAvatar($atts);
            list ($flat, $flng, $floc) = $fu->getLatLng(FALSE, FALSE);

            $tu = new User($this->dbhr, $this->dbhm, $msg['userid']);
            $s = $tu->getPrivate('settings');

            if ($s) {
                $settings = json_decode($s, TRUE);
                if (array_key_exists('useprofile', $settings) && !$settings['useprofile']) {
                    # Taker doesn't want their profile pic visible.  See above.
                    $ok = FALSE;
                }
            }

            if ($ok) {
                $ctx = NULL;
                $atts = $fu->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
                $tu->ensureAvatar($atts);
                list ($tlat, $tlng, $tloc) = $tu->getLatLng(FALSE, FALSE);

                # If we know precise locations for these users.
                if (($flat || $flng) && ($tlat || $tlng)) {
                    $this->create(
                        $msg['id'],
                        $msg['attid'],
                        $msg['timestamp'],
                        $msg['fromuser'],
                        $msg['userid'],
                        $flat,
                        $flng,
                        $tlat,
                        $tlng
                    );
                }

                # Find other people who replied and make sure they have avatars
                $others = $this->dbhr->preQuery("SELECT DISTINCT userid FROM chat_messages WHERE refmsgid = ? AND userid != ? AND userid != ?", [
                    $msg['id'],
                    $msg['userid'],
                    $msg['fromuser']
                ]);

                foreach ($others as $other) {
                    $u = User::get($this->dbhr, $this->dbhm, $other['userid']);
                    $ctx = NULL;
                    $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
                    $u->ensureAvatar($atts);
                }
            }
        }
    }

    public function getPublic() {
        $ret = $this->getAtts($this->publicatts);

        $ret['timestamp'] = Utils::ISODate($ret['timestamp']);

        # Blur the exact locations by rounding.
        foreach (['fromlat', 'fromlng', 'tolat', 'tolng'] as $f) {
            $ret[$f] = round($ret[$f], 3);
        }

        $a = new Attachment($this->dbhr, $this->dbhm, $ret['attid']);
        $a->getPath(TRUE);
        $ret['attachment'] = [
            'id' => $ret['attid'],
            'path' => $a->getPath(FALSE),
            'thumb' => $a->getPath(TRUE)
        ];
        
        $u = User::get($this->dbhr, $this->dbhm, $ret['fromuser']);
        $ctx = NULL;
        $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
        $ret['from'] = [
            'id' => $ret['fromuser'],
            'icon' => $atts['profile']['turl']
        ];
        unset($atts['fromuser']);

        $u = User::get($this->dbhr, $this->dbhm, $ret['touser']);
        $ctx = NULL;
        $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
        $ret['to'] = [
            'id' => $ret['touser'],
            'icon' => $atts['profile']['turl']
        ];
        unset($atts['touser']);

        # Find other people who replied.
        $others = $this->dbhr->preQuery("SELECT DISTINCT userid FROM chat_messages WHERE refmsgid = ? AND userid != ? AND userid != ?", [
            $ret['msgid'],
            $ret['touser'],
            $ret['fromuser']
        ]);

        $ret['others'] = [];

        foreach ($others as $other) {
            $u = User::get($this->dbhr, $this->dbhm, $other['userid']);
            $ctx = NULL;
            $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
            $u->ensureAvatar($atts);
            list ($lat, $lng) = $u->getLatLng(FALSE, FALSE, User::BLUR_100M);

            if ($lat || $lng) {
                $ret['others'][] = [
                    'id' => $other['userid'],
                    'icon' => $atts['profile']['turl'],
                    'lat' => round($lat, 3),
                    'lng' => round($lng, 3)
                ];
            }
        }
        
        unset($ret['attid']);

        return($ret);
    }

    public function getMessages($swlat, $swlng , $nelat, $nelng, $limit = 5, &$ctx) {
        $limit = intval($limit);
        $ctxq = $ctx ? (" id < " . intval($ctx) . ' AND ') : '';
        $sql = "SELECT id FROM visualise WHERE $ctxq fromlat BETWEEN ? AND ? AND fromlng BETWEEN ? AND ? ORDER BY id DESC LIMIT $limit;";

        $ret = [];

        if (($swlat || $swlng) && ($nelat || $nelng)) {
            $vs = $this->dbhr->preQuery($sql, [
                $swlat,
                $nelat,
                $swlng,
                $nelng
            ]);

            foreach ($vs as $v) {
                $av = new Visualise($this->dbhr, $this->dbhm, $v['id']);
                $ret[] = $av->getPublic();
                $ctx = $v['id'];
            }
        }

        return($ret);
    }
}