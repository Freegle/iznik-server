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

    public function query($lat, $lng) {
        # Ask for jobs which are not too far away.  0.33 degrees is tens of km.
        $jobs = $this->dbhr->preQuery("SELECT jobs.*, ST_Distance(geometry, POINT(?, ?)) AS dist FROM `jobs` HAVING dist < 0.33 ORDER BY posted_at DESC, dist ASC LIMIT 50;", [
            $lng,
            $lat
        ]);

        return $jobs;
    }
}