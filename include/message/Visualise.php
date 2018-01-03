<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

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
        $msgs = $this->dbhr->preQuery("SELECT DISTINCT messages.id, a.id AS attid, messages.fromuser, messages_outcomes.userid, messages.subject, messages_outcomes.timestamp FROM messages INNER JOIN messages_outcomes ON messages.id = messages_outcomes.msgid INNER JOIN messages_attachments a ON messages.id = a.msgid WHERE messages_outcomes.timestamp > ? AND outcome = ? AND userid IS NOT NULL;", [
            $mysqltime,
            Message::OUTCOME_TAKEN
        ]);

        foreach ($msgs as $msg) {
            error_log("#{$msg['id']}");
            $tlid = NULL;
            $flid = NULL;

            $fu = new User($this->dbhr, $this->dbhm, $msg['fromuser']);
            $atts = $fu->getPublic();
            $fu->ensureAvatar($atts);
            list ($flat, $flng) = $fu->getLatLng(FALSE, FALSE);

            $tu = new User($this->dbhr, $this->dbhm, $msg['userid']);
            $atts = $fu->getPublic();
            $tu->ensureAvatar($atts);
            list ($tlat, $tlng) = $tu->getLatLng(FALSE, FALSE);

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
                $atts = $u->getPublic();
                $u->ensureAvatar($atts);
            }
        }
    }

    public function getPublic() {
        $ret = $this->getAtts($this->publicatts);

        $ret['timestamp'] = ISODate($ret['timestamp']);

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
        $atts = $u->getPublic();
        $ret['from'] = [
            'id' => $ret['fromuser'],
            'icon' => $atts['profile']['turl']
        ];
        unset($atts['fromuser']);

        $u = User::get($this->dbhr, $this->dbhm, $ret['touser']);
        $atts = $u->getPublic();
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
            $atts = $u->getPublic();
            $u->ensureAvatar($atts);
            list ($lat, $lng) = $u->getLatLng(FALSE, FALSE);

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

    public function getMessages($swlat, $swlng , $nelat, $nelng, $ago = "midnight 7 days ago", $limit = 5) {
        $mysqltime = date("Y-m-d H:i:s", strtotime($ago));
        error_log("GetMessages $swlat, $swlng, $nelat, $nelng");

        $ret = [];

        if (($swlat || $swlng) && ($nelat || $nelng)) {
            $vs = $this->dbhr->preQuery("SELECT id FROM visualise WHERE timestamp >= ? AND fromlat BETWEEN ? AND ? AND fromlng BETWEEN ? AND ? ORDER BY timestamp DESC LIMIT $limit;", [
                $mysqltime,
                $swlat,
                $nelat,
                $swlng,
                $nelng
            ]);

            foreach ($vs as $v) {
                $av = new Visualise($this->dbhr, $this->dbhm, $v['id']);
                $ret[] = $av->getPublic();
            }
        }

        return($ret);
    }
}