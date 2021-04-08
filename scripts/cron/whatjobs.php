<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Stream;
use Prewk\XmlStringStreamer\Parser;

$lockh = Utils::lockScript(basename(__FILE__));

system('cd /tmp/; rm feed.xml; wget ' . WHATJOBS_DUMP . '; gzip -d feed.xml.gz');

$options = array(
    "captureDepth" => 3
);

$parser = new Parser\StringWalker($options);
$stream = new Stream\File('/tmp/feed.xml', 1024);
$streamer = new XmlStringStreamer($parser, $stream);

$count = 0;
$new = 0;

while ($node = $streamer->getNode()) {
    $job = simplexml_load_string($node);
    if (!$job->job_reference) {
        error_log("No job reference for {$job->title}, {$job->location}");
    } else {
        # See if we already have the job. If so, ignore it.  If it changes, tough.
        $existings = $dbhr->preQuery("SELECT job_reference FROM jobs WHERE job_reference = ?", [
            $job->job_reference
        ]);

        if (!count($existings)) {
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

                            $new++;
                        } catch (\Exception $e) {
                            error_log("Failed to add {$job->title} $geom");
                        }
                    }
                }
            }
        }

        $count++;

        if ($count % 1000 === 0) {
            error_log("...$count");
        }
    }
}

error_log("New jobs $new");
Utils::unlockScript($lockh);