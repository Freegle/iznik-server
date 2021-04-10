<?php
namespace Freegle\Iznik;

class Jobs {
    /** @public  $dbhr LoggedPDO */
    public $dbhr;
    /** @public  $dbhm LoggedPDO */
    public $dbhm;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function query($lat, $lng, $limit = 50, $category = NULL) {
        # To make efficient use of the spatial index we construct a box around our lat/lng, and search for jobs
        # where the geometry overlaps it.  We keep expanding our box until we fix enough.
        $ambit = 0.02;
        $ret = [];
        $got = [];
        $qlimit = min(100, $limit * 10);

        do {
            $swlat = $lat - $ambit;
            $nelat = $lat + $ambit;
            $swlng = $lng - $ambit;
            $nelng = $lng + $ambit;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
            $categoryq = $category ? (" AND category = " . $this->dbhr->quote($category)) : '';

            $sql = "SELECT ST_Distance(geometry, POINT($lng, $lat)) AS dist, ST_Area(geometry) AS area, jobs.* FROM `jobs`
WHERE ST_Intersects(geometry, GeomFromText('$poly')) 
    AND ST_Area(geometry) / ST_Area(GeomFromText('$poly')) < 2
    $categoryq
ORDER BY dist ASC, area ASC, posted_at DESC LIMIT $qlimit;";
            $jobs = $this->dbhr->preQuery($sql);
            shuffle($jobs);
            $jobs = array_slice($jobs, 0, 10);
            #error_log($sql . " found " . count($jobs));

            foreach ($jobs as $job) {
                if (!array_key_exists($job['id'], $got)) {
                    $got[$job['id']] = TRUE;
                    $ret[] = $job;
                }
            }

            $ambit *= 2;
        } while (count($ret) < $limit && $ambit < 1);

        return $ret;
    }

    public function get($id) {
        $jobs = $this->dbhr->preQuery("SELECT * FROM jobs WHERE id = ?", [
            $id
        ]);

        return $jobs;
    }
}