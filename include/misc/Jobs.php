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
        #
        # Show the closest first, then the ones with the smallest geometry (which pushes county jobs down), then
        # most recent.
        $jobs = $this->dbhr->preQuery("SELECT jobs.*, ST_Distance(geometry, POINT(?, ?)) AS dist FROM `jobs` HAVING dist < 0.33 AND city != '' ORDER BY dist ASC, ST_Area(geometry) ASC, posted_at DESC LIMIT 50;", [
            $lng,
            $lat
        ]);

        return $jobs;
    }
}