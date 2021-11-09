<?php
namespace Freegle\Iznik;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

class Isochrone extends Entity
{
    /** @var  $dbhm LoggedPDO */
    public $publicatts = [ 'id', 'userid', 'timestamp', 'polygon', 'nickname', 'locationid', 'transport', 'minutes' ];
    public $settableatts = ['transport', 'minutes'];
    public $isochrone = NULL;

    const WALK = 'Walk';
    const CYCLE = 'Cycle';
    const DRIVE = 'Drive';

    const DEFAULT_TIME = 25;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->name = 'isochrone';
        $this->table = 'isochrones';
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->id = $id;

        if ($id) {
            $this->fetch($this->dbhr, $this->dbhm, $id, 'isochrones', 'isochrone', NULL);
        }
    }

    function fetch(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $table, $name, $publicatts, $fetched = NULL, $allowcache = TRUE) {
        $isochrones = $this->dbhr->preQuery("SELECT id, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon FROM isochrones WHERE id = ?", [
            $this->id
        ]);

        foreach ($isochrones as $isochrone) {
            $this->isochrone = $isochrone;
        }
    }

    private function findLocation($userid, $locationid) {
        $u = new User($this->dbhr, $this->dbhm, $userid);

        if (!$locationid) {
            # Use the user's own location.
            list ($lat, $lng, $loc) = $u->getLatLng();
            $l = new Location($this->dbhr, $this->dbhm);
            $pc = $l->closestPostcode($lat, $lng);

            $locationid = NULL;

            if ($pc) {
                $locationid = $pc['id'];
            }
        } else {
            # Use specified location.
            $l = new Location($this->dbhr, $this->dbhm, $locationid);
            $lat = $l->getPrivate('lat');
            $lng = $l->getPrivate('lng');
        }

        return [ $lat, $lng, $locationid ];
    }

    public function create($userid, $transport, $minutes, $nickname = NULL, $locationid = NULL) {
        $id = NULL;

        list ($lat, $lng, $locationid) = $this->findLocation($userid, $locationid);
        $wkt = $this->fetchFromMapbox($transport, $lng, $lat, $minutes);

        if ($wkt) {
            $rc = $this->dbhm->preExec("INSERT INTO isochrones (userid, locationid, nickname, transport, minutes, polygon) VALUES (?, ?, ?, ?, ?, ST_GeomFromText(?, {$this->dbhr->SRID()}))", [
                $userid,
                $locationid,
                $nickname,
                $transport,
                $minutes,
                $wkt
            ]);

            if ($rc) {
                $id = $this->dbhm->lastInsertId();
                $this->id = $id;
                $this->fetch($this->dbhr, $this->dbhm, $id, 'isochrones', 'isochrone', NULL);
            }
        }

        return($id);
    }

    public function list($userid) {
        $isochrones = $this->dbhr->preQuery("SELECT id, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon FROM isochrones WHERE userid = ?;", [
            $userid,
        ]);

        foreach ($isochrones as &$isochrone) {
            $isochrone['timestamp'] = Utils::ISODate($isochrone['timestamp']);
            $l = new Location($this->dbhr, $this->dbhm, $isochrone['locationid']);
            $isochrone['location'] = $l->getPublic();
            unset($isochrone['locationid']);
        }

        return $isochrones;
    }

    public function refetch() {
        $l = new Location($this->dbhr, $this->dbhm, $this->isochrone['locationid']);
        $wkt = $this->fetchFromMapbox($this->isochrone['transport'], $l->getPrivate('lng'), $l->getPrivate('lat'), $this->isochrone['minutes']);

        if ($wkt) {
            $this->dbhm->preExec("UPDATE isochrones SET polygon = ST_GeomFromText(?, {$this->dbhr->SRID()}) WHERE id = ?;", [
                $wkt,
                $this->id
            ]);
        }
    }

    public function getPublic()
    {
        $ret = parent::getPublic();
        $ret['timestamp'] = Utils::ISODate($ret['timestamp']);
        return($ret);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM isochrones WHERE id = ?;", [ $this->id ]);
        return($rc);
    }

    public function deleteForUser($userid) {
        $rc = $this->dbhm->preExec("DELETE FROM isochrones WHERE userid = ?;", [ $userid]);
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

        $url = "https://api.mapbox.com/isochrone/v1/mapbox/$mapTrans/$lng,$lat.json?polygons=true&contours_minutes=$minutes&access_token=" . MAPBOX_TOKEN;
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        $json_response = curl_exec($curl);

        # This returns GeoJSON - we need to convert to WKT.
        $resp = json_decode($json_response, true);

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
}