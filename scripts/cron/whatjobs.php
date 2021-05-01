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

# Get the oldest date before we start because the script can run for ages.
$oldest = date('Y-m-d H:i:s', strtotime("9 hours ago"));

system('cd /tmp/; rm feed.xml*; wget -O - ' . WHATJOBS_DUMP . '| gzip -d -c > feed.xml');

$options = array(
    "captureDepth" => 3
);

$parser = new Parser\StringWalker($options);
$stream = new Stream\File('/tmp/feed.xml', 1024);
$streamer = new XmlStringStreamer($parser, $stream);

$count = 0;
$new = 0;
$old = 0;

$j = new Jobs($dbhr, $dbhm);
$maxish = $j->getMaxish();
$seen = [];

while ($node = $streamer->getNode()) {
    $job = simplexml_load_string($node);

    # Only add new jobs.
    $age = (time() - strtotime($job->posted_at)) / (60 * 60 * 24);

    if ($age < 7) {
        if (!$job->job_reference) {
            error_log("No job reference for {$job->title}, {$job->location}");
        } else {
            # See if we already have the job. If so, ignore it.  If it changes, tough.
            $existings = $dbhr->preQuery("SELECT id, job_reference FROM jobs WHERE job_reference = ?", [
                $job->job_reference
            ]);

            if (!count($existings)) {
                # Try to geocode the address.
                $addr = "{$job->city}, {$job->state}, {$job->country}";

                # See if we already have the address geocoded - would save hitting the geocoder.
                $geo = $dbhr->preQuery("SELECT AsText(geometry) AS geom FROM jobs WHERE city = ? AND state = ? AND country = ? LIMIT 1;", [
                    $job->city,
                    $job->state,
                    $job->country
                ]);

                $geom = NULL;

                if (count($geo)) {
                    $geom = $geo[0]['geom'];
                } else {
                    # We don't - so geocode it.
                    $geocode = @file_get_contents("https://" . GEOCODER . "/api?q=" . urlencode($addr) . "&bbox=-7.57216793459%2C49.959999905%2C1.68153079591%2C58.6350001085");

                    if ($geocode) {
                        $results = json_decode($geocode, true);

                        if (Utils::pres('features', $results) && count($results['features'])) {
                            $loc = $results['features'][0];

                            $geom = null;

                            if (Utils::pres('properties', $loc) && Utils::pres('extent', $loc['properties'])) {
                                $swlng = floatval($loc['properties']['extent'][0]);
                                $swlat = floatval($loc['properties']['extent'][1]);
                                $nelng = floatval($loc['properties']['extent'][2]);
                                $nelat = floatval($loc['properties']['extent'][3]);
                                $geom = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
                            } else {
                                if (Utils::pres('geometry', $loc) && Utils::pres('coordinates', $loc['geometry'])) {
                                    $geom = "POINT({$loc['geometry']['coordinates'][0]} {$loc['geometry']['coordinates'][1]})";
                                }
                            }
                        }
                    }
                }

                if ($geom) {
                    try {
                        $clickability = 0;
                        $title = NULL;

                        if ($job->title) {
                            $j = new Jobs($dbhr, $dbhm);
                            $title = str_replace('&#39;', "'", html_entity_decode($job->title));
                            $clickability = $j->clickability(NULL, html_entity_decode($title), $maxish);
                        }

                        $dbhm->preExec(
                            "INSERT INTO jobs (location, title, city, state, zip, country, job_type, posted_at, job_reference, company, mobile_friendly_apply, category, html_jobs, url, body, cpc, geometry, clickability, bodyhash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GeomFromText(?), ?, ?);",
                            [
                                $job->location ? html_entity_decode($job->location) : null,
                                $title,
                                $job->city ? html_entity_decode($job->city) : null,
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
                                $job->body ? html_entity_decode($job->body) : NULL,
                                $job->cpc ?? NULL,
                                $geom,
                                $clickability,
                                md5(substr($job->body, 0, 256))
                            ]
                        );
                        $new++;
                    } catch (\Exception $e) {
                        error_log("Failed to add {$job->title} $geom");
                    }
                }
            } else {
                # Leave clickability untouched for speed.  That means it'll be a bit wrong, but wrong values will
                # age out.
                $seen[] = $existings[0]['id'];

                if (count($seen) > 100) {
                    $dbhm->background("UPDATE jobs SET seenat = NOW() WHERE id IN (" . implode(',', $seen) . ");");
                    $seen = [];
                }
            }
        }
    } else {
        $old++;
    }

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count");
    }
}

if (count($seen)) {
    $dbhm->preExec("UPDATE jobs SET seenat = NOW() WHERE id IN (" . implode(',', $seen) . ");");
}

# Purge old jobs.
$purged = 0;
$olds = $dbhr->preQuery("SELECT id FROM jobs WHERE seenat < '$oldest' OR seenat IS NULL;");

foreach ($olds as $o) {
    $dbhm->preExec("DELETE FROM jobs WHERE id = ?;", [
        $o['id']
    ]);

    $purged++;

    if ($purged % 1000 === 0) {
        error_log("...$purged purged");
    }
}

# There are some "spammy" jobs which are posted with identical descriptions across the UK.  They feel scuzzy,
$spamcount = 0;
$spams = $dbhr->preQuery("SELECT COUNT(*) as count, title, bodyhash FROM jobs GROUP BY bodyhash HAVING count > 1000 AND bodyhash IS NOT NULL;");

foreach ($spams as $spam) {
    error_log("Delete spammy job {$spam['title']} * {$spam['count']}");
    $spamcount += $spam['count'];
    $dbhm->preExec("DELETE FROM jobs WHERE bodyhash = ?;", [
        $spam['bodyhash']
    ]);
}

error_log("New jobs $new, ignore $old, spammy $spamcount, purged $purged");
Utils::unlockScript($lockh);