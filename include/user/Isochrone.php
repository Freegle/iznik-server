<?php
namespace Freegle\Iznik;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

class Isochrone extends Entity
{
    /** @var  $dbhm LoggedPDO */
    public $publicatts = [ 'id', 'userid', 'timestamp', 'polygon' ];
    public $isochrone = NULL;

    const WALK = 'Walk';
    const CYCLE = 'Cycle';
    const DRIVE = 'Drive';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->name = 'isochrone';
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->id = $id;

        if ($id) {
            $this->fetch($this->dbhr, $this->dbhm, $id, 'isochrones', 'isochrone', NULL);
        }
    }

    function fetch(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $table, $name, $publicatts, $fetched = NULL, $allowcache = TRUE) {
        $isochrones = $this->dbhr->preQuery("SELECT id, userid, timestamp, ST_AsText(polygon) AS polygon FROM isochrones WHERE id = ?", [
            $this->id
        ]);

        foreach ($isochrones as $isochrone) {
            $this->isochrone = $isochrone;
        }
    }

    public function create($userid, $transport, $minutes = 30) {
        $id = NULL;

        $u = new User($this->dbhr, $this->dbhm, $userid);
        list ($lat, $lng, $loc) = $u->getLatLng();

        $mapTrans = NULL;
        switch ($transport) {
            case self::WALK: $mapTrans = 'walking'; break;
            case self::CYCLE: $mapTrans = 'cycling'; break;
            case self::DRIVE: $mapTrans = 'driving'; break;
        }

        if ($mapTrans) {
            $url = "https://api.mapbox.com/isochrone/v1/mapbox/$mapTrans/$lng,$lat.json?contours_minutes=$minutes&access_token=" . MAPBOX_TOKEN;
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            $json_response = curl_exec($curl);

            if ($json_response) {
                # This returns GeoJSON - we need to convert to WKT.
                $resp = json_decode($json_response, TRUE);

                if (Utils::pres('features', $resp) && count($resp['features']) == 1) {
                    $geom = $resp['features'][0];

                    if ($geom) {
                        $g = new \geoPHP();
                        $p = $g->load(json_encode($geom));
                        $wkt = $p->out('wkt');

                        $rc = $this->dbhm->preExec("INSERT INTO isochrones (userid, transport, minutes, polygon) VALUES (?, ?, ?, ST_GeomFromText(?, {$this->dbhr->SRID()}))", [
                            $userid,
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
                }
            }
        }

        return($id);
    }

    public function find($userid, $transport, $minutes = 30) {
        $ret = NULL;

        $isochrones = $this->dbhr->preQuery("SELECT id FROM isochrones WHERE userid = ? AND transport = ? AND minutes = ?;", [
            $userid,
            $transport,
            $minutes
        ]);

        foreach ($isochrones as $isochrone) {
            $ret = $isochrone['id'];
        }

        return $ret;
    }

    public function getPublic()
    {
        $ret = parent::getPublic();
        $ret['timestamp'] = Utils::ISODate($ret['timestamp']);

        if (Utils::pres('polygon', $ret)) {
            $g = new \geoPHP();
            $p = $g->load($ret['polygon']);
            $bbox = $p->getBBox();
            $ret['bbox'] = [
                'swlat' => $bbox['miny'],
                'swlng' => $bbox['minx'],
                'nelat' => $bbox['maxy'],
                'nelng' => $bbox['maxx'],
            ];
        }

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
}