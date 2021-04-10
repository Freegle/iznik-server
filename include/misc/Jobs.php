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
        # where the geometry overlaps it.  We keep expanding our box until we find enough.  We get more than
        # we need so that we can pick a random subset - that way the jobs people see vary a bit.
        $ambit = 0.02;
        $ret = [];
        $got = [];
        $qlimit = min(50, $limit * 5);
        $passes = 0;

        do {
            $swlat = $lat - $ambit;
            $nelat = $lat + $ambit;
            $swlng = $lng - $ambit;
            $nelng = $lng + $ambit;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
            $categoryq = $category ? (" AND category = " . $this->dbhr->quote($category)) : '';

            # We use ST_Within because that takes advantage of the spatial index, whereas ST_Intersects does not.
            $sql = "SELECT ST_Distance(geometry, POINT($lng, $lat)) AS dist, ST_Area(geometry) AS area, jobs.* FROM `jobs`
WHERE ST_Within(geometry, GeomFromText('$poly')) 
    AND ST_Area(geometry) / ST_Area(GeomFromText('$poly')) < 2
    $categoryq
ORDER BY dist ASC, area ASC, posted_at DESC LIMIT $qlimit;";
            $jobs = $this->dbhr->preQuery($sql);
            shuffle($jobs);
            $jobs = array_slice($jobs, 0, $limit);
            #error_log($sql . " found " . count($jobs));
            $passes++;

            foreach ($jobs as $job) {
                if (!array_key_exists($job['id'], $got)) {
                    $got[$job['id']] = TRUE;
                    $job['passes'] = $passes;
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