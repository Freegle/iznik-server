<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Fetch the dump file
$data = @file_get_contents(WHATJOBS_DUMP);

if ($data) {
    $uncompressed = gzdecode($data);

    if ($uncompressed) {
        $xml = simplexml_load_string($uncompressed);

        if ($xml->jobs) {
            $total = $xml->jobs_count;
            $count = 0;

            error_log("$total jobs");

            foreach ($xml->jobs as $j) {
                foreach ($j as $k) {
                    $job = $k;

                    if (!$job->job_reference) {
                        error_log("No job reference for {$job->title}, {$job->location}");
                    } else {
                        # Try to geocode the address.
                        $addr = "{$job->city}, {$job->state}, {$job->country}";

                        $geocode = @file_get_contents("https://" . GEOCODER . "/api?q=" . urlencode($addr) . "&bbox=-7.57216793459%2C49.959999905%2C1.68153079591%2C58.6350001085");

                        if ($geocode) {
                            $results = json_decode($geocode, TRUE);

                            if (Utils::pres('features', $results) && count($results['features'])) {
                                $loc = $results['features'][0];

                                $geom = NULL;

                                if (Utils::pres('properties', $loc) && Utils::pres('extent', $loc['properties'])) {
                                    $swlng = floatval($loc['properties']['extent'][0]);
                                    $swlat = floatval($loc['properties']['extent'][1]);
                                    $nelng = floatval($loc['properties']['extent'][2]);
                                    $nelat = floatval($loc['properties']['extent'][3]);
                                    $geom = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
                                } else if (Utils::pres('geometry', $loc) && Utils::pres('coordinates', $loc['geometry'])) {
                                    $geom = "POINT({$loc['geometry']['coordinates'][0]} {$loc['geometry']['coordinates'][1]})";
                                }

                                if ($geom) {
                                    try {
                                        $dbhm->preExec(
                                            "REPLACE INTO jobs (location, title, city, state, zip, country, job_type, posted_at, job_reference, company, mobile_friendly_apply, category, html_jobs, url, body, cpc, geometry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GeomFromText(?));",
                                            [
                                                $job->location ?? null,
                                                $job->title ?? null,
                                                $job->city ?? null,
                                                $job->state ?? null,
                                                $job->zip ?? null,
                                                $job->country ?? null,
                                                $job->job_type ?? null,
                                                $job->posted_at ?? null,
                                                $job->job_reference ?? null,
                                                $job->company ?? NULL,
                                                $job->mobile_friendly_apply ?? NULL,
                                                $job->category ?? NULL,
                                                $job->html_jobs ?? NULL,
                                                $job->url ?? NULL,
                                                $job->body ?? NULL,
                                                $job->cpc ?? NULL,
                                                $geom
                                            ]
                                        );
                                    } catch (\Exception $e) {
                                        error_log("Failed to add {$job->title} $geom");
                                    }
                                }
                            }
                        }

                        $count++;

                        if ($count % 1000 === 0) {
                            error_log("...$count / $total");
                        }
                    }
                }
            }
        }
    }
}

Utils::unlockScript($lockh);