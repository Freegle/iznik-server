<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Stream;
use Prewk\XmlStringStreamer\Parser;

# There are some cluster lockups which may be provoked by this script accessing two different servers for
# read and write.
$dbhr = $dbhm;

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
$toolow = 0;
$nogeocode = 0;

$j = new Jobs($dbhr, $dbhm);
$maxish = $j->getMaxish();
$seen = [];
$insertsql = '';
$batchcount = 100;

while ($node = $streamer->getNode()) {
    $job = simplexml_load_string($node);

    # Only add new jobs.
    $age = (time() - strtotime($job->posted_at)) / (60 * 60 * 24);

    if ($age < 7) {
        if (!$job->job_reference) {
            error_log("No job reference for {$job->title}, {$job->location}");
        } else if (floatval($job->cpc) < Jobs::MINIMUM_CPC) {
            # Ignore this job - not worth us showing.
            $toolow++;
        } else {
            # See if we already have the job. If so, ignore it.  If it changes, tough - it would take too long to
            # scan them all.
            $existings = $dbhr->preQuery("SELECT id, job_reference FROM jobs WHERE job_reference = ?", [
                $job->job_reference
            ]);

            if (!count($existings)) {
                # See if we already have the address geocoded - would save hitting the geocoder.
                $geo = $dbhr->preQuery("SELECT ST_AsText(geometry) AS geom FROM jobs WHERE city = ? AND state = ? AND country = ? LIMIT 1;", [
                    $job->city,
                    $job->state,
                    $job->country
                ]);

                $geom = NULL;
                error_log("Found " . count($geo) . " {$job->city}, {$job->state}, {$job->country}");

                if (count($geo)) {
                    $geom = $geo[0]['geom'];
                    error_log("Got existing location $geom");
                } else {
                    # Try to geocode the address.
                    #
                    # We have location, city, state, and country.  We can ignore the country.
                    $badStates = [ 'not specified', 'united kingdom of great britain and northern ireland', 'united kingdom', 'uk', 'england', 'scotland', 'wales', 'home based' ];
                    if ($job->state && strlen(trim($job->state)) && !in_array(strtolower(trim($job->state)), $badStates)) {
                        # Sometimes the state is a region.  Sometimes it is a much more specific location. Sigh.
                        #
                        # So first, geocode the state; if we get a specific location we can use that.  If it's a larger
                        # location then we can use it as a bounding box.
                        list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode($job->state, FALSE, TRUE);

                        if ($area && $area < 0.05) {
                            # We have a small 'state', which must be an actual location.  Stop.
                            error_log("Got small area {$job->state}");
                        } else if ($geom) {
                            # We have a large state, which is a plausible region. Use it as a bounding box for the
                            # city.
                            error_log("Gecoded {$job->state} to $geom");
                            list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode($job->city, TRUE, FALSE, $swlat, $swlng, $nelat, $nelng);

                            if (!$geom) {
                                error_log("Failed to geocode {$job->city} in {$job->state}");
                            }
                        }
                    }
                }

                $badCities = [ 'not specified', 'null', 'home based', 'united kingdom' ];
                if (!$geom && $job->city && strlen(trim($job->city)) && !in_array(trim($job->city), $badCities)) {
                    # We have not managed anything from the state.  Try just the city.  This will lead to some being
                    # wrong, but that's life.
                    error_log("State no use");
                    list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode($job->city, TRUE, FALSE);

                    if ($area > 50) {
                        error_log("{$job->city} => $geom is too large at $area");
                        $geom = NULL;
                    }
                }

                if ($geom) {
                    error_log("Geocoded {$job->city}, {$job->state} => $geom");

                    # Jobs tend to cluster, e.g. in cities.  When we are searching we expand a box from our current
                    # location until we overlap enough.  The clustering means we may suddenly match thousands of them,
                    # which is slow.  So instead of using the job location box as is, randomise to be a small box
                    # within the location box.  That way we will encounter some of the jobs sooner and hence have faster queries.
                    $newlat = $swlat + (mt_rand() / mt_getrandmax()) * ($nelat - $swlat);
                    $newlng = $swlng + (mt_rand() / mt_getrandmax()) * ($nelng - $swlng);
                    $swlng = $newlng - 0.0005;
                    $swlat = $newlat - 0.0005;
                    $nelat = $newlat + 0.0005;
                    $nelng = $newlng + 0.0005;
                    $geom = Utils::getBoxPoly($swlat, $swlng, $nelat, $nelng);
                    error_log("Modified loc to $geom");

                    try {
                        $clickability = 0;
                        $title = NULL;

                        if ($job->title) {
                            $j = new Jobs($dbhr, $dbhm);
                            $title = str_replace('&#39;', "'", html_entity_decode($job->title));
                            $clickability = $j->clickability(NULL, html_entity_decode($title), $maxish);
                        }

                        $batchcount++;
                        $insertsql .= "REPLACE INTO jobs (location, title, city, state, zip, country, job_type, posted_at, job_reference, company, mobile_friendly_apply, category, html_jobs, url, body, cpc, geometry, clickability, bodyhash, seenat, visible) VALUES (" .
                            ($job->location ? $dbhm->quote(html_entity_decode($job->location)) : 'NULL') . ", " .
                            $dbhm->quote($title) . ", " .
                            ($job->city ? $dbhm->quote(html_entity_decode($job->city)) : 'NULL') . ", " .
                            ($job->state ? $dbhm->quote(html_entity_decode($job->state)) : 'NULL') . ", " .
                            ($job->zip ? $dbhm->quote(html_entity_decode($job->zip)) : 'NULL') . ", " .
                            ($job->country ? $dbhm->quote(html_entity_decode($job->country)) : 'NULL') . ", " .
                            ($job->job_type ? $dbhm->quote(html_entity_decode($job->job_type)) : 'NULL') . ", " .
                            ($job->posted_at ? $dbhm->quote(html_entity_decode($job->posted_at)) : 'NULL') . ", " .
                            ($job->job_reference ? $dbhm->quote(html_entity_decode($job->job_reference)) : 'NULL') . ", " .
                            ($job->company ? $dbhm->quote(html_entity_decode($job->company)) : 'NULL') . ", " .
                            ($job->mobile_friendly_apply ? $dbhm->quote(html_entity_decode($job->mobile_friendly_apply)) : 'NULL') . ", " .
                            ($job->category ? $dbhm->quote(html_entity_decode($job->category)) : 'NULL') . ", " .
                            ($job->html_jobs ? $dbhm->quote(html_entity_decode($job->html_jobs)) : 'NULL') . ", " .
                            ($job->url ? $dbhm->quote(html_entity_decode($job->url)) : 'NULL') . ", " .
                            ($job->body ? $dbhm->quote(html_entity_decode($job->body)) : 'NULL') . ", " .
                            ($job->cpc ? $dbhm->quote(html_entity_decode($job->cpc)) : 'NULL') . ", " .
                            "ST_GeomFromText('$geom', {$dbhr->SRID()}), " .
                            $clickability . "," .
                            $dbhm->quote(md5(substr($job->body, 0, 256))) . ", " .
                            "NOW(), 0);";

                        if ($batchcount > 100) {
                            $dbhm->preExec($insertsql);
                            $insertsql = '';
                        }

                        $new++;
                    } catch (\Exception $e) {
                        error_log("Failed to add {$job->title} $geom " . $e->getMessage());
                    }
                } else {
                    error_log("Couldn't geocode {$job->city}, {$job->state}");
                    $nogeocode++;
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
        error_log(date("Y-m-d H:i:s", time()) . "...processing $count");
    }
}

if ($insertsql) {
    $dbhm->preExec($insertsql);
}

if (count($seen)) {
    $dbhm->preExec("UPDATE jobs SET seenat = NOW() WHERE id IN (" . implode(',', $seen) . ");");
}

# There are some "spammy" jobs which are posted with identical descriptions across the UK.  They feel scuzzy, so
# remove them.
$spamcount = 0;
$spams = $dbhr->preQuery("SELECT COUNT(*) as count, title, bodyhash FROM jobs GROUP BY bodyhash HAVING count > 50 AND bodyhash IS NOT NULL;");

error_log(date("Y-m-d H:i:s", time()) . "Delete spammy jobs");

foreach ($spams as $spam) {
    error_log("Delete spammy job {$spam['title']} * {$spam['count']}");
    $spamcount += $spam['count'];
    do {
        $dbhm->preExec("DELETE FROM jobs WHERE bodyhash = ? LIMIT 1;", [
            $spam['bodyhash']
        ]);
    } while ($dbhm->rowsAffected());
}

# Now make new jobs visible.
$invis = $dbhr->preQuery("SELECT id FROM jobs WHERE visible = 0;");
error_log("Set new jobs visible " . count($invis));

foreach ($invis as $inv) {
    $dbhm->preExec("UPDATE jobs SET visible = 1 WHERE id = ?;", [
        $inv['id']
    ]);
}

# Purge old jobs.  whatjobs_purge should have kept this to a minimum.
error_log("Purge old jobs");
$purged = 0;

do {
    $dbhm->preExec("DELETE FROM jobs WHERE seenat < '$oldest' LIMIT 1;");
    $thispurge = $dbhm->rowsAffected();

    $purged += $thispurge;

    if ($purged % 1000 === 0) {
        error_log(date("Y-m-d H:i:s", time()) . "...$purged purged");
    }
} while ($thispurge);

error_log("New jobs $new, nogeocode $nogeocode, too low $toolow, ignore $old, spammy $spamcount, purged $purged");
Utils::unlockScript($lockh);