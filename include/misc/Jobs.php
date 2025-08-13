<?php
namespace Freegle\Iznik;

use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Stream;
use Prewk\XmlStringStreamer\Parser;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

class Jobs {
    /** @public  $dbhr LoggedPDO */
    public $dbhr;
    /** @public  $dbhm LoggedPDO */
    public $dbhm;

    private $jobKeywords = NULL;

    const MINIMUM_CPC = 0.10;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function query($lat, $lng, $limit = 50, $category = NULL) {
        # To make efficient use of the spatial index we construct a box around our lat/lng, and search for jobs
        # where the geometry overlaps it.  We keep expanding our box until we find enough.
        #
        # We used to double the ambit each time, but that led to long queries, probably because we would suddenly
        # include a couple of cities or something.
        $step = 0.02;
        $ambit = $step;

        $ret = [];
        $got = [];
        $gotbody = [];
        $gottitle = [];
        $passes = 0;

        do {
            $swlat = $lat - $ambit;
            $nelat = $lat + $ambit;
            $swlng = $lng - $ambit;
            $nelng = $lng + $ambit;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
            $categoryq = $category ? (" AND category = " . $this->dbhr->quote($category)) : '';

            # We use ST_Within because that takes advantage of the spatial index, whereas ST_Intersects does not.
            $alreadyq = '';

            if (count($got)) {
                $alreadyq = " AND jobs.id NOT IN (" . implode(',', array_keys($got)) . ") AND jobs.title NOT IN (";

                $first = TRUE;
                foreach ($gottitle as $title) {
                    if (!$first) {
                        $alreadyq .= ", ";
                    }

                    $alreadyq .= $this->dbhr->quote($title);
                    $first = FALSE;
                }

                $alreadyq .= ") ";
            }

            $sql = "SELECT $ambit AS ambit, 
       ST_Distance(geometry, ST_GeomFromText('POINT($lng $lat)', {$this->dbhr->SRID()})) AS dist,
       CASE WHEN ST_Dimension(geometry) < 2 THEN 0 ELSE ST_Area(geometry) END AS area,
       jobs.id, jobs.url, jobs.title, jobs.location, jobs.body, jobs.job_reference, jobs.cpc, jobs.clickability
        FROM `jobs`
        WHERE ST_Within(geometry, ST_GeomFromText('$poly', {$this->dbhr->SRID()})) 
            AND (ST_Dimension(geometry) < 2 OR ST_Area(geometry) / ST_Area(ST_GeomFromText('$poly', {$this->dbhr->SRID()})) < 2)
            AND cpc >= " . Jobs::MINIMUM_CPC . "
            AND visible = 1
            $alreadyq
            $categoryq
        ORDER BY cpc DESC, dist ASC, posted_at DESC LIMIT $limit;";
            $jobs = $this->dbhr->preQuery($sql);
            #error_log($sql . " found " . count($jobs));
            $passes++;

            foreach ($jobs as $job) {
                $got[$job['id']] = TRUE;
                $gotbody[$job['body']] = $job['body'];
                $gottitle[$job['title']] = $job['title'];
                $job['passes'] = $passes;

                # We have clickability, which is our estimate of how likely people are to click on a job based on
                # past clicks.  We also have the CPC, which is how much we expect to earn from a click.
                # Use these to order the jobs by our expected income.
                $cpc = max($job['cpc'], 0.0001);
                $job['expectation'] = $cpc * $job['clickability'];

                $ret[] = $job;
            }

            $ambit += $step;
        } while (count($ret) < $limit && $ambit < 1);

        usort($ret, function($a, $b) {
            # Take care - must return integer, not float, otherwise the sort doesn't work.
            return ceil($b['cpc'] - $a['cpc']);
        });

        $ret = array_slice($ret, 0, $limit);

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
        # Use ignore because we can get clicks for jobs we have purged.
        $this->dbhm->preExec("INSERT IGNORE INTO logs_jobs (userid, jobid, link) VALUES (?, ?, ?);", [
            $userid,
            $jobid,
            $link
        ]);
    }

    public function analyseClickability() {
        # This looks at the jobs we've clicked on, extracts keywords, and uses that to build a measure of how
        # clickable a job is.  Obviously this is distorted by the order in which we display jobs, but it
        # still gives a reasonable idea of the keywords which would generate the most clicks.
        #
        # Prune old job logs - old data not worth analysing.
        $mysqltime = date("Y-m-d H:i:s", strtotime("midnight 31 days ago"));
        $this->dbhm->preExec("DELETE FROM logs_jobs WHERE timestamp < ?", [
            $mysqltime
        ]);

        # Find any jobs which have the link but not the id.
        $logs = $this->dbhr->preQuery("SELECT jobs.id AS jobid, logs_jobs.id FROM logs_jobs 
    INNER JOIN jobs ON jobs.url = logs_jobs.link 
    WHERE jobid IS NULL AND link IS NOT NULL
    ORDER BY id DESC;");
        error_log("Logs to fix " . count($logs));
        $count = 0;

        foreach ($logs as $log) {
            $this->dbhm->preExec("UPDATE logs_jobs SET jobid = ? WHERE id = ?;", [
                $log['jobid'],
                $log['id']
            ]);

            $count++;

            if ($count % 100 == 0) {
                error_log("...$count / " . count($logs));
            }
        }

        # Now process the clicked jobs to extract keywords.  Use DISTINCT as some people click obsessively on the same job.
        $jobs = $this->dbhr->preQuery("SELECT DISTINCT jobs.* FROM logs_jobs INNER JOIN jobs ON logs_jobs.jobid = jobs.id");
        $this->dbhm->preExec("TRUNCATE TABLE jobs_keywords");

        error_log("Process " . count($jobs));

        foreach ($jobs as $job) {
            $keywords = Jobs::getKeywords($job['title']);

            if (count($keywords)) {
                #error_log("{$job['title']} => " . json_encode($keywords));
                foreach ($keywords as $k) {
                    $this->dbhm->preExec("INSERT INTO jobs_keywords (keyword, count) VALUES (?, 1) ON DUPLICATE KEY UPDATE count = count + 1;", [
                        $k
                    ]);
                }
            }
        }
    }

    public function updateClickability() {
        # This updates the list of jobs with a clickability measure, based on the keyword analyse we have done on
        # previous clicks.
        $jobs = $this->dbhr->preQuery("SELECT id, title FROM jobs;");

        $maxish = $this->getMaxish();

        foreach ($jobs as $job) {
            $score = $this->clickability($job['id'], $job['title'], $maxish);
            $this->dbhm->preExec("UPDATE jobs SET clickability = ? WHERE id = ?;", [
                $score,
                $job['id']
            ]);
        }
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
                if (!$this->jobKeywords) {
                    # Cache in this object because it speeds bulk load a lot.
                    $this->jobKeywords = [];
                    $jobKeywords = $this->dbhr->preQuery("SELECT * FROM jobs_keywords");

                    foreach ($jobKeywords as $j) {
                        $this->jobKeywords[$j['keyword']] = $j['count'];
                    }
                }

                foreach ($keywords as $keyword) {
                    if (array_key_exists($keyword, $this->jobKeywords)) {
                        $ret += $this->jobKeywords[$keyword];
                    }
                }
            }
        }

        # Normalise this, so that if we get more clicks overall because we have more site activity we don't
        # think it's because the jobs we happen to be clicking are more desirable. Use the 95th percentile to
        # get the maxish value (avoiding outliers).
        if (!$maxish) {
            $maxish = $this->getMaxish();
        }

        return $ret / $maxish;
    }

    public function getMaxish() {
        $maxish = 1;

        $m = $this->dbhr->preQuery("SELECT count FROM 
(SELECT t.*,  @row_num :=@row_num + 1 AS row_num FROM jobs_keywords t, 
    (SELECT @row_num:=0) counter ORDER BY count) 
temp WHERE temp.row_num = ROUND (.95* @row_num);");

        if (count($m)) {
            $maxish = $m[0]['count'];
        }

        return $maxish;
    }

    public static function geocode($addr, $allowPoint, $exact, $bbswlat = 49.959999905, $bbswlng = -7.57216793459, $bbnelat = 58.6350001085, $bbnelng = 1.68153079591) {
        // Special cases
        if ($addr == 'West Marsh') {
            $addr = 'Grimsby';
        } else if ($addr == 'Stoney Middleton') {
            $addr .= ', Derbyshire';
        } else if ($addr == 'Middleton Stoney') {
            $addr .= ', Oxfordshire';
        } else if ($addr == 'Kirkwall') {
            $addr .= ', Orkney';
        } else if ($addr == 'City of York') {
            $addr = 'York';
        } else if ($addr == 'Sutton Central') {
            $addr = 'Sutton, London';
        } else if ($addr == 'Hampden Park') {
            $addr = 'Hampden Park, Eastbourne';
        } else if ($addr == 'Guernsey') {
            return [ NULL, NULL, NULL, NULL, NULL, NULL ];
        }

        $url = "https://" . GEOCODER . "/api?q=" . urlencode($addr) . "&bbox=$bbswlng%2C$bbswlat%2C$bbnelng%2C$bbnelat";
        $geocode = @file_get_contents($url);
        #error_log("Geocode $addr, allow point $allowPoint, exact $exact, $url");
        $swlng = $swlat = $nelng = $nelat = $geom = $area = NULL;

        if ($geocode) {
            $results = json_decode($geocode, TRUE);

            if (Utils::pres('features', $results) && count($results['features'])) {
                foreach ($results['features'] as $feature) {
                    if (Utils::pres('properties', $feature)) {
                        $nameMatches = Utils::pres('name', $feature['properties']) && strcmp(strtolower($feature['properties']['name']), strtolower($addr)) == 0;

                        if (Utils::pres('extent', $feature['properties'])) {
                            if (!$exact || !$nameMatches) {
                                $swlng = floatval($feature['properties']['extent'][0]);
                                $swlat = floatval($feature['properties']['extent'][1]);
                                $nelng = floatval($feature['properties']['extent'][2]);
                                $nelat = floatval($feature['properties']['extent'][3]);
                                $geom = Utils::getBoxPoly($swlat, $swlng, $nelat, $nelng);
                                #error_log("From extent $geom");
                            }
                            break;
                        } else if ($allowPoint &&
                            (!$exact || $nameMatches) &&
                            Utils::pres('geometry', $feature) &&
                            Utils::pres('coordinates', $feature['geometry'])) {
                            # Invent a small polygon, just so all the geometries have the same dimension
                            $lat = floatval($feature['geometry']['coordinates'][1]);
                            $lng = floatval($feature['geometry']['coordinates'][0]);
                            $swlng = $lng - 0.0005;
                            $swlat = $lat - 0.0005;
                            $nelat = $lat + 0.0005;
                            $nelng = $lng + 0.0005;
                            $geom = Utils::getBoxPoly($swlat, $swlng, $nelat, $nelng);
                            #error_log("From point $geom");
                            break;
                        }
                    }
                }
            }
        }

        if ($geom) {
            $g = new \geoPHP();
            $poly = $g::load($geom, 'wkt');
            $area = $poly->area();
        }

        #error_log("Geocode $addr => $geom area $area");
        return [ $swlat, $swlng, $nelat, $nelng, $geom, $area ];
    }

    public function scanToCSV($inputFile, $outputFile, $maxage = 7, $fakeTime = FALSE, $distribute = 0.0005, $perFile = 1000, $format) {
        $now = $fakeTime ? '2001-01-01 00:00:00' : date("Y-m-d H:i:s", time());
        $out = fopen("$outputFile.tmp", 'w');

        # This scans the XML job file provided by WhatJobs, filters out the ones we want, and writes to a
        # CSV file.
        $options = array(
            "captureDepth" => 3,
            "expectGT" => TRUE
        );

        $parser = new Parser\StringWalker($options);
        $stream = new Stream\File($inputFile, 1024);
        $streamer = new XmlStringStreamer($parser, $stream);

        $count = 0;
        $new = 0;
        $old = 0;
        $toolow = 0;
        $nogeocode = 0;
        $geocache = [];

        $maxish = $this->getMaxish();

        while ($node = $streamer->getNode()) {
            $job = simplexml_load_string($node);

            if ($format == 1) {
                $location = $job->locations->location->location;
                $timeposted = $job->timePosted;
                $city = $job->locations->location->city;
                $state = $job->locations->location->state;
                $zip = $job->locations->location->zip;
                $country = $job->locations->location->country;
                $companyname = $job->company->name;
                $cpc = $job->custom->CPC;
                $deeplink = $job->urlDeeplink;
                $jobid = $job->id;
                $jobtitle = $job->title;
                $description = $job->description;
                $category = $job->category;
            } else {
                $location = $job->location;
                $timeposted = $job->posted_at;
                $city = $job->city;
                $state = $job->state;
                $zip = $job->zip;
                $country = $job->country;
                $companyname = $job->company;
                $cpc = $job->cpc;
                $deeplink = $job->url;
                $jobid = $job->job_reference;
                $jobtitle = $job->title;
                $description = $job->body;
                $category = $job->category;
            }

            #error_log("Location $location, timeposted $timeposted, city $city, state $state, zip $zip, country $country, companyname $companyname, cpc $cpc, deeplink $deeplink, jobid $jobid, jobtitle $jobtitle, description " . strlen($description) . ", category $category");

            # Only add new jobs.
            $age = (time() - strtotime($timeposted)) / (60 * 60 * 24);

            if ($age < $maxage) {
                if (!$jobid) {
                    #error_log("No job id for {$jobtitle}, {$location}");
                } else if (floatval($cpc) < Jobs::MINIMUM_CPC) {
                    # Ignore this job - not worth us showing.
                    $toolow++;
                } else {
                    $geokey = "{$city},{$state},{$country}";
                    $geom = NULL;

                    $wascached = array_key_exists($geokey, $geocache);

                    if ($wascached) {
                        # In memory cache is faster than DB queries.  The total number of locations of the order
                        # of 20K so this is ok.
                        list ($geom, $swlat, $swlng, $nelat, $nelng) = $geocache[$geokey];
                        #error_log("Got $geokey from geocache");
                    } else {
                        # See if we already have the address geocoded - would save hitting the geocoder.
                        $geo = $this->dbhr->preQuery(
                            "SELECT ST_AsText(ST_Envelope(geometry)) AS geom FROM jobs WHERE city = ? AND state = ? AND country = ? LIMIT 1;",
                            [
                                $city,
                                $state,
                                $country
                            ]
                        );

                        $geom = null;
                        #error_log("Found " . count($geo) . " {$city}, {$state}, {$country}");

                        if (count($geo))
                        {
                            $geom = $geo[0]['geom'];
                            $g = new \geoPHP();
                            $poly = $g::load($geom, 'wkt');
                            $bbox = $poly->getBBox();
                            $swlat = $bbox['miny'];
                            $swlng = $bbox['minx'];
                            $nelat = $bbox['maxy'];
                            $nelng = $bbox['maxx'];

                            #error_log("Got existing location $geom = $swlat,$swlng to $nelat, $nelng");
                        }
                    }

                    if (!$geom) {
                        # Try to geocode the address.
                        #
                        # We have location, city, state, and country.  We can ignore the country.
                        $badStates = [ 'not specified', 'united kingdom of great britain and northern ireland', 'united kingdom', 'uk', 'england', 'scotland', 'wales', 'home based' ];
                        if ($state && strlen(trim($state)) && !in_array(strtolower(trim($state)), $badStates)) {
                            # Sometimes the state is a region.  Sometimes it is a much more specific location. Sigh.
                            #
                            # So first, geocode the state; if we get a specific location we can use that.  If it's a larger
                            # location then we can use it as a bounding box.
                            #
                            # Geocoding gets confused by "Borough of", e.g. "Borough of Swindon".
                            $state = str_ireplace($state, 'Borough of ', '');

                            list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode($job, FALSE, TRUE);

                            if ($area && $area < 0.05) {
                                # We have a small 'state', which must be an actual location.  Stop.
                                #error_log("Got small area {$state}");
                            } else if ($geom) {
                                # We have a large state, which is a plausible region. Use it as a bounding box for the
                                # city.
                                #error_log("Gecoded {$state} to $geom");
                                list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode($city, TRUE, FALSE, $swlat, $swlng, $nelat, $nelng);

                                if (!$geom) {
                                    #error_log("Failed to geocode {$city} in {$state}");
                                }
                            }
                        }
                    }

                    $badCities = [ 'not specified', 'null', 'home based', 'united kingdom', ', , united kingdom' ];
                    if (!$geom && $city && strlen(trim($city)) && !in_array(strtolower(trim($city)), $badCities)) {
                        # We have not managed anything from the state.  Try just the city.  This will lead to some being
                        # wrong, but that's life.
                        #error_log("State no use, use city {$city}");
                        list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode($city, TRUE, FALSE);

                        if ($area > 50) {
                            #error_log("{$city} => $geom is too large at $area");
                            $geom = NULL;
                        }
                    }

                    if ($geom) {
                        #error_log("Geocoded {$city}, {$state} => $geom");

                        if (!$wascached) {
                            $geocache[$geokey] = [ $geom, $swlat, $swlng, $nelat, $nelng];
                        }

                        # Jobs tend to cluster, e.g. in cities.  When we are searching we expand a box from our current
                        # location until we overlap enough.  The clustering means we may suddenly match thousands of them,
                        # which is slow.  So instead of using the job location box as is, randomise to be a small box
                        # within the location box.  That way we will encounter some of the jobs sooner and hence have faster queries.
                        $newlat = $swlat + (mt_rand() / mt_getrandmax()) * ($nelat - $swlat);
                        $newlng = $swlng + (mt_rand() / mt_getrandmax()) * ($nelng - $swlng);
                        $swlng = $newlng - $distribute;
                        $swlat = $newlat - $distribute;
                        $nelat = $newlat + $distribute;
                        $nelng = $newlng + $distribute;
                        $geom = $distribute ? Utils::getBoxPoly($swlat, $swlng, $nelat, $nelng) : $geom;
                        #error_log("Modified loc to $geom");

                        # If the job already exists in the old table we want to preserve the id, because people
                        # might click on an old id from email.  If we don't find it then we'll use NULL, which will
                        # create a new auto-increment id.  We have to go to some effort to get the NULL in there -
                        # we want \N, but unquoted, so we put \N and then strip the quotes later.
                        #
                        # We could speed this up by sending out the job_reference rather than the id.
                        $id = "\N";

                        $oldids = $this->dbhr->preQuery("SELECT id FROM jobs WHERE job_reference = ?;", [
                            $jobid
                        ]);

                        foreach ($oldids as $oldid) {
                            $id = $oldid['id'];
                        }

                        try {
                            #error_log("Added job $jobid $id $geom");
                            $clickability = 0;
                            $title = NULL;

                            if ($jobtitle) {
                                $title = str_replace('&#39;', "'", html_entity_decode($jobtitle));
                                # Don't get clickability - we don't currently use it and it slows things down.
                                $clickability = 1;
                                #$clickability = $this->clickability(NULL, html_entity_decode($title), $maxish);
                            }

                            $body = NULL;

                            if ($description) {
                                # Truncate the body - we only display the body as a snippet to get people to click
                                # through.
                                #
                                # Fix up line endings which cause problems with fputcsv, and other weirdo characters
                                # that get mangled en route to us.  This isn't a simple UTF8 problem because the data
                                # comes from all over the place and is a bit of a mess.
                                $body = html_entity_decode($description);
                                $body = str_replace("\r\n", " ", $body);
                                $body = str_replace("\r", " ", $body);
                                $body = str_replace("\n", " ", $body);
                                $body = str_replace('–', '-', $body);
                                $body = str_replace('Â', '-', $body);
                                $body = substr($body, 0, 256);

                                # The truncation might happen to leave a \ at the end of the string, which would then
                                # escape the following quote added by fputcsv, and the world would explode in a ball of
                                # fire.  Add a space to avoid that.
                                $body .= ' ';
                            }

                            # Sometimes the geocode can end up with line breaks.
                            $geom = str_replace(["\n", "\r"], '', $geom);

                            # Write the job to CSV, ready for LOAD DATA INFILE later.
                            # location, title, city, state, zip, country, job_type, posted_at, job_reference, company,
                            # mobile_friendly_apply, category, html_jobs, url, body, cpc, geometry, clickability,
                            # bodyhash, seenat
                            fputcsv($out, [
                                $id,
                                $location ? html_entity_decode($location) : NULL,
                                $title,
                                $city ? html_entity_decode($city) : NULL,
                                $state ? html_entity_decode($state) : NULL,
                                $zip ? html_entity_decode($zip) : NULL,
                                $country ? html_entity_decode($country) : NULL,
                                NULL,
                                $timeposted ? html_entity_decode($timeposted) : NULL,
                                $jobid ? html_entity_decode($jobid) : NULL,
                                $companyname ? html_entity_decode($companyname) : NULL,
                                NULL,
                                $category ? html_entity_decode($category) : NULL,
                                NULL,
                                $deeplink ? html_entity_decode($deeplink) : NULL,
                                $body,
                                $cpc ? html_entity_decode($cpc) : NULL,
                                $geom,
                                $clickability,
                                md5($body),
                                $now,
                                1
                            ]);

                            $new++;
                        } catch (\Exception $e) {
                            error_log("Failed to add {$jobtitle} $geom " . $e->getMessage());
                        }
                    } else {
                        #error_log("Couldn't geocode {$city}, {$state}");
                        $nogeocode++;
                    }
                }
            } else {
                $old++;
            }

            $count++;

            if ($count % 1000 === 0) {
                error_log(date("Y-m-d H:i:s", time()) . "...processing $count");
            }
        }

        fclose($out);

        # Now unquote any "\N" into \N.
        error_log(date("Y-m-d H:i:s", time()) . "...finished loop, split");
        $data = file_get_contents("$outputFile.tmp");
        $data = str_replace('"\N"', '\N', $data);
        file_put_contents($outputFile, $data);
        error_log(date("Y-m-d H:i:s", time()) . "...written file $outputFile");
    }

    public function prepareForLoadCSV() {
        error_log(date("Y-m-d H:i:s", time()) . "...DROP jobs_new");
        $this->dbhm->preExec("DROP TABLE IF EXISTS jobs_new;");
        error_log(date("Y-m-d H:i:s", time()) . "...CREATE jobs_new");
        $this->dbhm->preExec("CREATE TABLE jobs_new LIKE jobs;");
        $this->dbhm->preExec("SET GLOBAL local_infile=1;");
    }

    public function loadCSV($csv) {
        # Percona cluster has non-standard behaviour for LOAD DATA INFILE, and commits are performed every 10K rows.
        # But even this seems to place too much load on cluster syncing, and can cause lockups.  So we split the CSV
        # into 1K rows and load each of them in turn.
        error_log(date("Y-m-d H:i:s", time()) . "...Split CSV file");
        system("cd /tmp/; rm feed-split*; split -1000 $csv feed-split");
        error_log(date("Y-m-d H:i:s", time()) . "...load files");
        foreach (glob('/tmp/feed-split*') as $fn) {
            set_time_limit(600);
            $this->dbhm->preExec("LOAD DATA LOCAL INFILE '$fn' INTO TABLE jobs_new
            CHARACTER SET latin1
            FIELDS TERMINATED BY ',' 
            OPTIONALLY ENCLOSED BY '\"' 
            LINES TERMINATED BY '\n'
            (id, location, title, city, state, zip, country, job_type, posted_at, job_reference, company,
             mobile_friendly_apply, category, html_jobs, url, body, cpc, @GEOM, clickability,
             bodyhash, seenat, visible) SET geometry = ST_GeomFromText(@GEOM, " . $this->dbhm->SRID() . ");");
            error_log(date("Y-m-d H:i:s", time()) . "...loaded file $fn");
        }
        error_log(date("Y-m-d H:i:s", time()) . "...finished file load");
    }

    public function deleteSpammyJobs($table) {
        # There are some "spammy" jobs which are posted with identical descriptions across the UK.  They feel scuzzy, so
        # remove them.
        $spamcount = 0;
        error_log(date("Y-m-d H:i:s", time()) . "Count spammy jobs");
        $spams = $this->dbhr->preQuery("SELECT COUNT(*) as count, title, bodyhash FROM $table GROUP BY bodyhash HAVING count > 50 AND bodyhash IS NOT NULL;");

        error_log(date("Y-m-d H:i:s", time()) . "Delete spammy jobs");

        foreach ($spams as $spam) {
            error_log("Delete spammy job {$spam['title']} * {$spam['count']}");
            set_time_limit(600);
            $spamcount += $spam['count'];

            do {
                $this->dbhm->preExec("DELETE FROM $table WHERE bodyhash = ? LIMIT 1;", [
                    $spam['bodyhash']
                ]);
            } while ($this->dbhm->rowsAffected());
        }
    }

    public function swapTables() {
        # We want to swap the jobs_new table with the jobs table, atomically.
        error_log(date("Y-m-d H:i:s", time()) . " Swap tables...");
        $this->dbhm->preExec("DROP TABLE IF EXISTS jobs_old;");
        $this->dbhm->preExec("RENAME TABLE jobs TO jobs_old, jobs_new TO jobs;");
        $this->dbhm->preExec("DROP TABLE IF EXISTS jobs_old;");
        error_log(date("Y-m-d H:i:s", time()) . "...tables swapped...");
    }
}