<?php
namespace Freegle\Iznik;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

class Isochrone extends Entity
{
    /** @var  $dbhm LoggedPDO */
    public $settableatts = ['transport', 'minutes'];
    public $isochrone = NULL;

    const WALK = 'Walk';
    const CYCLE = 'Cycle';
    const DRIVE = 'Drive';

    const DEFAULT_TIME = 15;
    const MIN_TIME = 5;
    const MAX_TIME = 45;

    const SIMPLIFY = 0.01;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->name = 'isochrone';
        $this->table = 'isochrones';
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->id = $id;

        if ($id) {
            $this->fetchIt($id);
        }
    }

    private function fetchIt($id) {
        $isochrones = $this->dbhr->preQuery("SELECT isochrones_users.id, isochroneid, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon FROM isochrones_users INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id WHERE isochrones_users.id = ?", [
            $id,
        ]);

        foreach ($isochrones as $isochrone) {
            $isochrone['timestamp'] = Utils::ISODate($isochrone['timestamp']);
            $this->isochrone = $isochrone;
        }
    }

    public function getPublic() {
        return $this->isochrone;
    }

    private function findLocation($userid, $locationid) {
        $u = new User($this->dbhr, $this->dbhm, $userid);

        if (!$locationid) {
            # Use the user's own location.  Don't want to use the global defaults otherwise everyone will have a
            # location near Dunsop Bridge.
            list ($lat, $lng, $loc) = $u->getLatLng(FALSE, TRUE);
            $locationid = NULL;

            if ($loc) {
                $l = new Location($this->dbhr, $this->dbhm);
                $pc = $l->closestPostcode($lat, $lng);

                if ($pc) {
                    $locationid = $pc['id'];
                }
            }
        } else {
            # Use specified location.
            $l = new Location($this->dbhr, $this->dbhm, $locationid);
            $lat = $l->getPrivate('lat');
            $lng = $l->getPrivate('lng');
        }

        return [ $lat, $lng, $locationid ];
    }

    private function ensureIsochroneExists($locationid, $minutes, $transport) {
        # See if we already have one.
        $transq = $transport ? (" AND transport = " . $this->dbhr->quote($transport)) : " AND transport IS NULL";
        $existings = $this->dbhr->preQuery("SELECT id FROM isochrones WHERE locationid = ? $transq AND minutes = ? ORDER BY timestamp DESC LIMIT 1;", [
            $locationid,
            $minutes
        ]);

        $rc = FALSE;
        $isochroneid = NULL;

        if (count($existings)) {
            # We have an existing one for these parameters.  Copy it for this user.
            $isochroneid = $existings[0]['id'];
        } else {
            # Need to create one.
            $l = new Location($this->dbhr, $this->dbhm, $locationid);
            $lat = $l->getPrivate('lat');
            $lng = $l->getPrivate('lng');

            $wkt = $this->fetchFromMapbox($transport, $lng, $lat, $minutes);

            if ($wkt) {
                $rc = $this->dbhm->preExec("INSERT INTO isochrones (locationid, transport, minutes, polygon) VALUES (?, ?, ?, 
                 CASE WHEN ST_SIMPLIFY(ST_GeomFromText(?, {$this->dbhr->SRID()}), ?) IS NULL THEN ST_GeomFromText(?, {$this->dbhr->SRID()}) ELSE ST_SIMPLIFY(ST_GeomFromText(?, {$this->dbhr->SRID()}), ?) END
                                                                         )", [
                    $locationid,
                    $transport,
                    $minutes,
                    $wkt,
                    self::SIMPLIFY,
                    $wkt,
                    $wkt,
                    self::SIMPLIFY
                ]);
            }

            if ($rc) {
                $isochroneid = $this->dbhm->lastInsertId();
            }
        }

        return $isochroneid;
    }

    public function create($userid, $transport, $minutes, $nickname, $locationid) {
        $id = NULL;

        list ($lat, $lng, $locationid) = $this->findLocation($userid, $locationid);

        if ($locationid) {
            $minutes = min(self::MAX_TIME, $minutes);
            $minutes = max(self::MIN_TIME, $minutes);

            $isochroneid = $this->ensureIsochroneExists($locationid, $minutes, $transport);

            if ($isochroneid) {
                $rc = $this->dbhm->preExec("INSERT INTO isochrones_users (userid, isochroneid, nickname) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE nickname = ?, id=LAST_INSERT_ID(id);", [
                    $userid,
                    $isochroneid,
                    $nickname,
                    $nickname
                ]);

                if ($rc) {
                    $id = $this->dbhm->lastInsertId();
                    $this->fetchIt($id);
                }
            }
        }

        return($id);
    }

    public function list($userid, $all = FALSE) {
        $sql = $all ?
            "SELECT isochrones.id, locationid, isochrones.timestamp, transport, minutes, ST_AsText(polygon) AS polygon, lat, lng FROM isochrones_users INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id INNER JOIN locations ON locations.id = isochrones.locationid ORDER BY isochrones_users.id DESC LIMIT 100;" :
            "SELECT isochrones_users.id, isochroneid, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon FROM isochrones_users INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id WHERE userid = " . intval($userid) . " ORDER BY isochrones_users.id ASC;";

        $isochrones = $this->dbhr->preQuery($sql, []);

        foreach ($isochrones as &$isochrone) {
            $isochrone['timestamp'] = Utils::ISODate($isochrone['timestamp']);
            $l = new Location($this->dbhr, $this->dbhm, $isochrone['locationid']);
            $isochrone['location'] = $l->getPublic();
            unset($isochrone['locationid']);
        }

        return $isochrones;
    }

    public function decoupleFromUser() {
        $this->dbhm->preQuery("DELETE FROM isochrones_users WHERE id = ?", [
            $this->id
        ]);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM isochrones WHERE id = ?;", [ $this->id ]);
        return($rc);
    }

    public function deleteForUser($userid) {
        $rc = $this->dbhm->preExec("DELETE FROM isochrones_users WHERE userid = ?;", [ $userid]);
        return($rc);
    }

    public function fetchFromMapbox($transport, $lng, $lat, $minutes) {
        $wkt = NULL;

        switch ($transport) {
            case self::WALK: $mapTrans = 'walking'; break;
            case self::CYCLE: $mapTrans = 'cycling'; break;
            case self::DRIVE: $mapTrans = 'driving'; break;
            default:
                // We assume driving if they've not specified a preference.  This means that any preference they
                // have specified is explicit.
                $mapTrans = 'driving';
                break;
        }

        $url = "https://api.mapbox.com/isochrone/v1/mapbox/$mapTrans/$lng,$lat.json?polygons=TRUE&contours_minutes=$minutes&access_token=" . MAPBOX_TOKEN;
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        $json_response = curl_exec($curl);

        # This returns GeoJSON - we need to convert to WKT.
        $resp = json_decode($json_response, TRUE);

        if (Utils::pres('features', $resp) && count($resp['features']) == 1) {
            $geom = $resp['features'][0];

            if ($geom)
            {
                $g = new \geoPHP();
                $p = $g->load(json_encode($geom));
                $wkt = $p->out('wkt');
            }
        }

        return $wkt;
    }

    public function edit($minutes, $transport) {
        # We have been passed new minutes and transport values. We want to preserve the id in the isochrones_users
        # table, but point it at a new isochrone with those values in the isochrones table.
        #
        # So first make sure there is one.
        $isochroneid = $this->ensureIsochroneExists($this->isochrone['locationid'], $minutes, $transport);

        # And update this entry.
        if ($isochroneid) {
            try {
                $this->dbhm->preExec("UPDATE isochrones_users SET isochroneid = ? WHERE id = ?;", [
                    $isochroneid,
                    $this->id
                ]);
            } catch (DBException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== FALSE) {
                    # This can happen due to a timing window.  We already have an entry for this user/isochrone, so
                    # we no longer need this one.
                    $this->dbhm->preExec("DELETE FROM isochrones_users WHERE id = ?;", [
                        $this->id
                    ]);
                }
            }
        }
    }
}