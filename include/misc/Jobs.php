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

        # Sort the resulting jobs by clickability, which should result in more clicks.
        usort($ret, function($a, $b) {
            return $b['clickability'] - $a['clickability'];
        });

        return $ret;
    }

    public function get($id) {
        $jobs = $this->dbhr->preQuery("SELECT * FROM jobs WHERE id = ?", [
            $id
        ]);

        return $jobs;
    }

    public static function getKeywords($str) {
        $initial = explode(' ',$str);

        # Remove some stuff.
        $arr = [];

        foreach ($initial as $i) {
            $w = preg_replace("/[^A-Za-z]/", '', $i);

            if (strlen($w) > 2) {
                $arr[] = $w;
            }
        }

        $result = [];

        for($i=0; $i < count($arr)-1; $i++) {
            $result[] =  strtolower($arr[$i]) . ' ' . strtolower($arr[$i+1]);
        }

        return $result;
    }

    public function recordClick($jobid, $link, $userid) {
        $this->dbhm->preExec("INSERT INTO logs_jobs (userid, jobid, link) VALUES (?, ?, ?);", [
            $userid,
            $jobid,
            $link
        ]);
    }

    public function clickability($jobid, $title = NULL, $maxish = NULL) {
        $ret = 0;

        if (!$title) {
            # Collect the sum of the counts of keywords present in this title.
            $jobs = $this->dbhr->preQuery("SELECT title FROM jobs WHERE id = ?;", [
                $jobid
            ]);

            foreach ($jobs as $job) {
                $title = $job['title'];
            }
        }

        if ($title) {
            $keywords = Jobs::getKeywords($title);

            if (count($keywords)) {
                $kq = '';

                foreach ($keywords as $k) {
                   if (strlen($kq)) {
                       $kq .= ", " . $this->dbhr->quote($k);
                   }  else {
                       $kq = $this->dbhr->quote($k);
                   }
                }

                $sql = "SELECT count, keyword FROM jobs_keywords WHERE keyword IN ($kq);";
                $counts = $this->dbhr->preQuery($sql);

                foreach ($keywords as $keyword) {
                    foreach ($counts as $count) {
                        if ($count['keyword'] == $keyword) {
                            $ret += $count['count'];
                        }
                    }
                }
            }
        }

        # Normalise this, so that if we get more clicks overall because we have more site activity we don't
        # think it's because the jobs we happen to be clicking are more desirable. Use the 95th percentile to
        # get the maxish value (avoiding outliers).
        if (!$maxish) {
            $m = $this->dbhr->preQuery("SELECT count FROM 
(SELECT t.*,  @row_num :=@row_num + 1 AS row_num FROM jobs_keywords t, 
    (SELECT @row_num:=0) counter ORDER BY count) 
temp WHERE temp.row_num = ROUND (.95* @row_num);");

            if (count($m)) {
                $maxish = $m[0]['count'];
            }
        }

        return $ret / $maxish;
    }
}