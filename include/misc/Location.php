<?php
namespace Freegle\Iznik;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');

class Location extends Entity
{
    const NEARBY = 50; // In miles.
    const QUITENEARBY = 15; // In miles.
    const TOO_LARGE = 0.3;
    const TOO_SMALL = 0.001;
    const LOCATION_STEP = 0.005;
    const LOCATION_MAX = 100;

    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'osm_id', 'name', 'type', 'popularity', 'gridid', 'postcodeid', 'areaid', 'lat', 'lng', 'maxdimension');

    /** @var  $log Log */
    private $log;
    var $loc;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $fetched = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'locations', 'loc', $this->publicatts, $fetched);
        $this->log = new Log($dbhr, $dbhm);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function canon($str) {
        # There are some commom abbreviations which people might use, which we should expand.
        $str = preg_replace('/^St\b/', 'Saint', $str);
        $str = preg_replace('/\bSt\b/', 'Street', $str);
        $str = preg_replace('/\bRd\b/', 'Road', $str);
        $str = preg_replace('/\bAvenue\b/', 'Av', $str);
        $str = preg_replace('/\bDr\b/', 'Drive', $str);
        $str = preg_replace('/\bLn\b/', 'Lane', $str);
        $str = preg_replace('/\bPl\b/', 'Place', $str);
        $str = preg_replace('/\bSq\b/', 'Square', $str);
        $str = preg_replace('/\bCls\b/', 'Close', $str);
        $str = strtolower(preg_replace("/[^A-Za-z0-9]/", '', $str));

        return($str);
    }

    public function create($osm_id, $name, $type, $geometry)
    {
        try {
            $rc = $this->dbhm->preExec("INSERT INTO locations (osm_id, name, type, geometry, canon, osm_place, maxdimension) VALUES (?, ?, ?, ST_GeomFromText(?, {$this->dbhr->SRID()}), ?, ?, GetMaxDimension(ST_GeomFromText(?, {$this->dbhr->SRID()})))",
                [$osm_id, $name, $type, $geometry, $this->canon($name), 0, $geometry]);
            $id = $this->dbhm->lastInsertId();

            $this->dbhm->preExec("INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, {$this->dbhr->SRID()}));", [
                $id,
                $geometry
            ]);

            if ($rc) {
                # Although this is something we can derive from the geometry, it speeds things up a lot to have it cached.
                $rc = $this->dbhm->preExec("UPDATE locations SET lng = ST_X(ST_Centroid(geometry)), lat = ST_Y(ST_Centroid(geometry)) WHERE id = ?;",
                    [ $id ]);
            }

            $sql = "SELECT locations_grids.id AS gridid FROM `locations` INNER JOIN locations_grids ON locations.id = ? AND MBRIntersects(locations.geometry, locations_grids.box) LIMIT 1;";
            #error_log("SELECT locations_grids.id AS gridid FROM `locations` INNER JOIN locations_grids ON locations.id = $id AND MBRIntersects(locations.geometry, locations_grids.box) LIMIT 1;");
            $grids = $this->dbhr->preQuery($sql, [ $id ]);
            foreach ($grids as $grid) {
                $gridid = $grid['gridid'];
                $sql = "UPDATE locations SET gridid = ? WHERE id = ?;";
                $this->dbhm->preExec($sql, [ $grid['gridid'], $id ]);
            }

            $p = strpos($name, ' ');

            if ($type == 'Polygon') {
                if (PGSQLHOST) {
                    # This is an area.  Copy into Postgres for future mapping. If we fail, carry on.  There is a
                    # background cron job to save us.
                    try {
                        $pgsql = new LoggedPDO(PGSQLHOST, PGSQLDB, PGSQLUSER, PGSQLPASSWORD, FALSE, NULL, 'pgsql');
                        $pgsql->allDownRetries = 0;

                        $pgsql->preExec("INSERT INTO locations (locationid, name, type, area, location)
                        VALUES (?, ?, ?, ST_Area(ST_GeomFromText(?, {$this->dbhr->SRID()})), ST_GeomFromText(?, {$this->dbhr->SRID()}));", [
                            $id, $name, $type, $geometry, $geometry
                        ]);
                    } catch (\Exception $e) {}

                    # Map this new postcode to an area.
                    $this->remapPostcodes($geometry);
                }
            }

            if ($type == 'Postcode' && $p !== FALSE) {
                # This is a full postcode - find the parent postcode.
                $sql = "SELECT id FROM locations WHERE name LIKE ? AND type = 'Postcode';";
                $pcs = $this->dbhm->preQuery($sql, [ substr($name, 0, $p) ]);
                foreach ($pcs as $pc) {
                    $this->dbhm->preExec("UPDATE locations SET postcodeid = ? WHERE id = ?;", [
                       $pc['id'],
                       $id
                    ]);
                }

                # Map this new postcode to an area.
                $this->remapPostcodes($geometry);
            }

        } catch (\Exception $e) {
            error_log("Location create exception " . $e->getMessage() . " " . $e->getTraceAsString());
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'locations', 'loc', $this->publicatts);
            $this->log->log([
                'type' => Log::TYPE_LOCATION,
                'subtype' => Log::SUBTYPE_CREATED,
                'user' => $id,
                'text' => $name
            ]);

            return ($id);
        } else {
            return (NULL);
        }
    }

    public function getGrid() {
        $ret = NULL;
        $sql = "SELECT * FROM locations_grids WHERE id = ?;";
        $locs = $this->dbhr->preQuery($sql, [ $this->loc['gridid'] ]);
        foreach ($locs as $loc) {
            $ret = $loc;
        }

        return($ret);
    }

    public function search($term, $groupid, $limit = 10) {
        $limit = intval($limit);

        # Remove any weird characters.
        $term = preg_replace("/[^[:alnum:][:space:]]/u", '', $term);
        
        # We have a large table of locations.  We want to search within the ones which are close to this group, so
        # we look in the same or adjacent grid squares.
        $termt = trim($term);
        $gridids = [];
        $ret = [];

        # We want to exclude some locations.
        $exclgroup = " LEFT JOIN locations_excluded ON locations.id = locations_excluded.locationid ";

        # Exclude all numeric locations (there are some in OSM).  Also exclude amenities and shops, otherwise we get
        # some silly mappings (e.g. London).
        $exclude = " AND NOT canon REGEXP '^-?[0-9]+$' AND osm_amenity = 0 AND osm_shop = 0 AND locations_excluded.locationid IS NULL ";

        # Find the gridid for the group.
        $sql = "SELECT locations_grids.* FROM locations_grids INNER JOIN `groups` ON groups.id = ? AND swlat <= groups.lat AND swlng <= groups.lng AND nelat > groups.lat AND nelng > groups.lng;";
        #error_log("$sql $groupid");
        $grids = $this->dbhr->preQuery($sql, [
            $groupid
        ]);
        
        foreach ($grids as $grid) {
            $gridids[] = $grid['id'];

            # Now find grids within approximately 30 miles of that.
            #$sql = "SELECT id FROM locations_grids WHERE haversine(" . ($grid['swlat'] + 0.05) . ", " . ($grid['swlng'] + 0.05) . ", swlat + 0.05, swlng + 0.05) < 30;";
            $sql = "SELECT id FROM locations_grids WHERE ABS(" . $grid['swlat'] . " - swlat) <= 0.4 AND ABS(" . $grid['swlng']. " - swlng) <= 0.4;";
            $neighbours = $this->dbhr->preQuery($sql);
            foreach ($neighbours as $neighbour) {
                $gridids[] = $neighbour['id'];
            }

            # Now we have a list of gridids within which we want to search.
            #error_log("Check grids " . implode(',', $gridids));
            if (count($gridids) > 0) {
                # First we do a simple match.  If the location is correct, that will find it quickly.
                $term2 = $this->dbhr->quote($this->canon($term));
                $sql = "SELECT locations.* FROM locations $exclgroup WHERE canon = $term2 AND gridid IN (" . implode(',', $gridids) . ") $exclude ORDER BY LENGTH(canon) ASC, popularity DESC LIMIT $limit;";
                $locs = $this->dbhr->preQuery($sql);

                foreach ($locs as $loc) {
                    $ret[] = $loc;
                    $limit--;
                }

                # Look for a known location which contains the location we've specified.  This will scan quite a lot of
                # locations, because that kind of search can't use the name index, but it is restricted by grids and therefore won't be
                # appalling.
                #
                # We want the matches that are closest in length to the term we're trying to match first
                # (you might have 'Stockbridge' and 'Stockbridge Church Of England Primary School'), then ordered
                # by most popular.
                if ($limit > 0) {
                    $reg = $this->dbhr->isV8() ? ("'\\\\b', " . $this->dbhr->quote($termt) . ", '\\\\b'"): ("'[[:<:]]', " . $this->dbhr->quote($termt) . ", '[[:>:]]'");
                    $sql = "SELECT locations.* FROM locations FORCE INDEX (gridid) $exclgroup WHERE LENGTH(name) >= " . strlen($termt) . " AND name NOT LIKE '%(%' AND name NOT LIKE '%)%' AND name NOT LIKE '%?%' AND name REGEXP CONCAT($reg) AND gridid IN (" . implode(',', $gridids) . ") $exclude ORDER BY ABS(LENGTH(name) - " . strlen($term) . ") ASC, popularity DESC LIMIT $limit;";
                    $locs = $this->dbhr->preQuery($sql);

                    foreach ($locs as $loc) {
                        $ret[] = $loc;
                        $limit--;
                    }
                }

                if ($limit > 0) {
                    # We didn't find as many as we wanted.  It's possible that the location text actually contains
                    # two locations, most commonly a place and a postcode.  So do an (even slower) search to find
                    # locations in our table which appear somewhere in the subject.  Ignore very short ones or
                    # ones which are less than half the length of what we're looking for (to speed up the search).
                    #
                    # We also order to find the one most similar in length.
                    $reg = $this->dbhr->isV8() ? "'\\\\b', name, '\\\\b'": "'[[:<:]]', name, '[[:>:]]'";
                    $sql = "SELECT locations.* FROM locations $exclgroup WHERE gridid IN (" . implode(',', $gridids) . ") AND LENGTH(canon) > 2 AND LENGTH(name) >= " . strlen($termt)/2 . " AND name NOT LIKE '%(%' AND name NOT LIKE '%)%' AND name NOT LIKE '%?%' AND " . $this->dbhr->quote($termt) . " REGEXP CONCAT($reg) $exclude ORDER BY ABS(LENGTH(name) - " . strlen($term) . "), GetMaxDimension(locations.geometry) ASC, popularity DESC LIMIT $limit;";
                    $locs = $this->dbhr->preQuery($sql);

                    foreach ($locs as $loc) {
                        $ret[] = $loc;
                        $limit--;
                    }
                }

                if ($limit > 0) {
                    # We still didn't find as many results as we wanted.  Do a (slow) search using a Damerau-Levenshtein
                    # distance function to spot typos, transpositions, spurious spaces etc.
                    $sql = "SELECT locations.* FROM locations $exclgroup WHERE gridid IN (" . implode(',', $gridids) . ") AND DAMLEVLIM(`canon`, " .
                        $this->dbhr->quote($this->canon($term)) . ", " . strlen($term) . ") < 2 $exclude ORDER BY ABS(LENGTH(canon) - " . strlen($term) . ") ASC, popularity DESC LIMIT $limit;";
                    #error_log("DamLeve $sql");
                    $locs = $this->dbhr->preQuery($sql);

                    foreach ($locs as $loc) {
                        $ret[] = $loc;
                        $limit--;
                    }
                }

            }
        }

        # Don't return duplicates.
        $ret = array_unique($ret, SORT_REGULAR);

        # We might have acquired a few too many.
        $ret = array_slice($ret, 0, 10);

        return($ret);
    }

    public function locsForGroup($groupid) {
        # We have a large table of locations.  We want to return the ones which are close to this group, so
        # we look in the same or adjacent grid squares.
        $gridids = [];
        $ret = [];

        # Find the gridid for the group.
        $sql = "SELECT locations_grids.* FROM locations_grids INNER JOIN `groups` ON groups.id = ? AND swlat <= groups.lat AND swlng <= groups.lng AND nelat > groups.lat AND nelng > groups.lng;";
        $grids = $this->dbhr->preQuery($sql, [
            $groupid
        ]);

        foreach ($grids as $grid) {
            $gridids[] = $grid['id'];

            # Now find grids which touch that.  That avoids issues where our group is near the boundary of a grid square.
            $sql = "SELECT touches FROM locations_grids_touches WHERE gridid = ?;";
            $neighbours = $this->dbhr->preQuery($sql, [ $grid['id'] ]);
            foreach ($neighbours as $neighbour) {
                $gridids[] = $neighbour['touches'];
            }

            # Now we have a list of gridids within which we want to find locations.
            #error_log("Got gridids " . var_export($gridids, TRUE));
            if (count($gridids) > 0) {
                $sql = "SELECT locations.* FROM locations WHERE gridid IN (" . implode(',', $gridids) . ") AND LENGTH(TRIM(name)) > 0 ORDER BY popularity ASC;";
                #error_log("Get locs in grids $sql");
                $ret = $this->dbhr->preQuery($sql);
            }
        }

        return($ret);
    }

    public function exclude($groupid, $userid, $byname) {
        # We want to exclude a specific location.  Potentially exclude all locations with the same name as this one; our DB has
        # duplicate names.
        $sql = $byname ? "SELECT id FROM locations WHERE name = (SELECT name FROM locations WHERE id = ?);" : "SELECT id FROM locations WHERE id = ?;";
        $locs = $this->dbhr->preQuery($sql, [ $this->id ]);

        foreach ($locs as $loc) {
            # Mark location as blocked for this group, so it won't be suggested again.
            $sql = "REPLACE INTO locations_excluded (locationid, groupid, userid) VALUES (?,?,?);";
            $rc = $this->dbhm->preExec($sql, [
                $loc['id'],
                $groupid,
                $userid
            ]);
        }

        # Not the end of the world if this doesn't work.
        return(TRUE);
    }

    public function delete()
    {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $rc = $this->dbhm->preExec("DELETE FROM locations WHERE id = ?;", [$this->id]);

        if (PGSQLHOST) {
            # Delete from Postgresql too.  If we fail, carry on.  There is a background cron job to save us.
            try {
                $pgsql = new LoggedPDO(PGSQLHOST, PGSQLDB, PGSQLUSER, PGSQLPASSWORD, FALSE, NULL, 'pgsql');
                $pgsql->allDownRetries = 0;

                if ($pgsql)
                {
                    $pgsql->preExec("DELETE FROM locations WHERE locationid = ?;", [
                        $this->id
                    ]);
                }
            } catch (\Exception $e) {}
        }

        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_LOCATION,
                'subtype' => Log::SUBTYPE_DELETED,
                'user' => $this->id,
                'byuser' => $me ? $me->getId() : NULL,
                'text' => $this->loc['name']
            ]);
        }

        return ($rc);
    }

    public static function getDistance($latitude1, $longitude1, $latitude2, $longitude2)
    {
        $earth_radius = 3956;

        $dLat = deg2rad($latitude2 - $latitude1);
        $dLon = deg2rad($longitude2 - $longitude1);

        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * asin(sqrt($a));
        $d = $earth_radius * $c;

        return $d;
    }

    public function closestPostcode($lat, $lng) {
        # We use our spatial index to narrow down the locations to search through; we start off very close to the
        # point and work outwards. That way in densely postcoded areas we have a fast query, and in less dense
        # areas we have some queries which are quick but don't return anything.
        $scan = 0.00001953125;
        $ret = NULL;
        $lat = floatval($lat);
        $lng = floatval($lng);

        do {
            $swlat = $lat - $scan;
            $nelat = $lat + $scan;
            $swlng = $lng - $scan;
            $nelng = $lng + $scan;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
            $sql = "SELECT id, name, areaid, lat, lng, ST_distance(locations_spatial.geometry, ST_GeomFromText('POINT($lng $lat)', {$this->dbhr->SRID()})) AS dist FROM locations_spatial 
    INNER JOIN locations ON locations.id = locations_spatial.locationid 
    WHERE MBRContains(ST_Envelope(ST_GeomFromText('$poly', {$this->dbhr->SRID()})), locations_spatial.geometry) AND type = 'Postcode' AND LOCATE(' ', locations.name) > 0
    ORDER BY dist ASC, CASE WHEN ST_Dimension(locations_spatial.geometry) < 2 THEN 0 ELSE ST_AREA(locations_spatial.geometry) END ASC LIMIT 1;";
            $locs = $this->dbhr->preQuery($sql);

            if (count($locs) == 1) {
                $ret = $locs[0];

                if ($ret['areaid']) {
                    $l = new Location($this->dbhr, $this->dbhm, $ret['areaid']);
                    $ret['area'] = $l->getPublic();
                    unset($ret['areaid']);
                }

                $l = new Location($this->dbhr, $this->dbhm, $ret['id']);
                $ret['groupsnear'] = $l->groupsNear(Location::NEARBY, TRUE);
                break;
            }

            $scan *= 2;
        } while ($scan <= 0.2);

        return($ret);
    }

    public function groupsNear($radius = Location::NEARBY, $expand = FALSE, $limit = 10) {
        $limit = intval($limit);

        # To make this efficient we want to use the spatial index on polyindex.  But our groups are not evenly
        # distributed, so if we search immediately upto $radius, which is the maximum we need to cover, then we
        # will often have to scan many more groups than we need in order to determine the closest groups
        # (via the LIMIT clause), and this may be slow even with a spatial index.
        #
        # For example, searching in London will find ~120 groups within 50 miles, of which we are only interested
        # in 10, and the query will take ~0.03s.  If we search within 4 miles, that will typically find what we
        # need and the query takes ~0.00s.
        #
        # So we step up, using a bounding box that covers the point and radius and searching based on the lat/lng
        # centre of the group.  That's much faster.  But (infuriatingly) there are some groups which are so large that
        # the centre of the group is further away than the centre of lots of other groups, and that means that
        # we don't find the correct group.  So to deal with such groups we have an alt lat/lng which we can set to
        # be somewhere else, effectively giving the group two "centres".  This is a fudge which clearly wouldn't
        # cope with arbitrary geographies or hyperdimensional quintuple manifolds or whatever, but works ok for our
        # little old UK reuse network.
        $currradius = round($radius / 16 + 0.5, 0);
        
        do {
            $ne = \GreatCircle::getPositionByDistance(sqrt($currradius*$currradius*2)*1609.34, 45, $this->loc['lat'], $this->loc['lng']);
            $sw = \GreatCircle::getPositionByDistance(sqrt($currradius*$currradius*2)*1609.34, 225, $this->loc['lat'], $this->loc['lng']);

            $box = "ST_GeomFromText('POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))', {$this->dbhr->SRID()})";

            # We order by the distance to the group polygon (dist), rather than to the centre (hav), because that
            # reflects which group you are genuinely closest to.
            #
            # Favour groups hosted by us if there's a tie.
            $sql = "SELECT id, nameshort, ST_distance(ST_GeomFromText('POINT({$this->loc['lng']} {$this->loc['lat']})', {$this->dbhr->SRID()}), polyindex) * 111195 * 0.000621371 AS dist, haversine(lat, lng, {$this->loc['lat']}, {$this->loc['lng']}) AS hav, CASE WHEN altlat IS NOT NULL THEN haversine(altlat, altlng, {$this->loc['lat']}, {$this->loc['lng']}) ELSE NULL END AS hav2 FROM `groups` WHERE MBRIntersects(polyindex, $box) AND publish = 1 AND listable = 1 HAVING (hav IS NOT NULL AND hav < $currradius OR hav2 IS NOT NULL AND hav2 < $currradius) ORDER BY dist ASC, hav ASC, external ASC LIMIT $limit;";
            #error_log("Find near $sql");
            $groups = $this->dbhr->preQuery($sql);

            $ret = [];

            foreach ($groups as $group) {
                if ($expand) {
                    $g = Group::get($this->dbhr, $this->dbhm, $group['id']);
                    $thisone = $g->getPublic();

                    $thisone['distance'] = $group['hav'];
                    $thisone['polydist'] = $group['dist'];

                    $ret[] = $thisone;
                } else {
                    $ret[] = $group['id'];
                }
            }
            
            $currradius *= 2;
        } while (count($ret) < $limit && $currradius <= $radius);

        return($ret);
    }

    public function typeahead($query, $limit = 10, $near = TRUE, $postcode = TRUE) {
        # We want to select full postcodes (with a space in them)
        $stripped = preg_replace('/\s/', '', $query);
        $postcodeq = $postcode ? " AND name LIKE '% %' AND type = 'Postcode'" : '';
        $sql = "SELECT * FROM locations WHERE canon LIKE ? $postcodeq LIMIT $limit;";
        $pcs = $this->dbhr->preQuery($sql, [
            "$stripped%"
        ]);
        $ret = [];
        foreach ($pcs as $pc) {
            $thisone = [];
            foreach ($this->publicatts as $att) {
                $thisone[$att] = $pc[$att];
            }

            if ($near && strpos($pc['name'], ' ') !== FALSE) {
                // Only return groups near for full postcode match.
                $l = new Location($this->dbhr, $this->dbhm, $pc['id']);
                $thisone['groupsnear'] = $l->groupsNear(Location::NEARBY, TRUE);
            }

            if ($thisone['areaid']) {
                $l = new Location($this->dbhr, $this->dbhm, $thisone['areaid']);
                $thisone['area'] = $l->getPublic();
                unset($thisone['areaid']);
            }

            $ret[] = $thisone;
        }

        if (count($ret) ==  1) {
            # Just one; worth recording the popularity.
            $this->dbhm->background("UPDATE locations SET popularity = popularity + 1 WHERE id = {$ret[0]['id']}");
        }

        return($ret);
    }

    public function findByName($query)
    {
        $canon = $this->canon($query);
        $sql = "SELECT * FROM locations WHERE canon LIKE ? LIMIT 1;";
        $locs = $this->dbhr->preQuery($sql, [$canon]);
        return (count($locs) == 1 ? $locs[0]['id'] : NULL);
    }

    public function setGeometry($val) {
        $rc = FALSE;

        $valid = $this->dbhm->preQuery("SELECT ST_IsValid(ST_GeomFromText(?, {$this->dbhr->SRID()})) AS valid, ST_AsText(ST_Simplify(ST_GeomFromText(?, {$this->dbhr->SRID()}), ?)) AS simp;", [
            $val,
            $val,
            LoggedPDO::SIMPLIFY
        ]);

        foreach ($valid as $v) {
            if ($v['valid']) {
                $oldval = $this->dbhr->preQuery("SELECT ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geometry, 
            CASE WHEN ST_Intersects(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END, ST_GeomFromText(?, {$this->dbhr->SRID()}))
            THEN ST_AsText(ST_UNION(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END, ST_GeomFromText(?, {$this->dbhr->SRID()})))
            ELSE NULL    
            END AS unioned FROM locations WHERE id = ?;", [
                    $val,
                    $val,
                    $this->id
                ]);

                # Use simplified value.
                $val = $v['simp'];

                $rc = $this->dbhm->preExec(
                    "UPDATE locations SET `type` = 'Polygon', `ourgeometry` = ST_GeomFromText(?, {$this->dbhr->SRID()}) WHERE id = {$this->id};",
                    [$val]
                );

                if ($rc) {
                    # Put in the index table.
                    $this->dbhm->preExec(
                        "REPLACE INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, {$this->dbhr->SRID()}));",
                        [
                            $this->id,
                            $val
                        ]
                    );

                    # The centre point and max dimensions will also have changed.
                    $rc = $this->dbhm->preExec(
                        "UPDATE locations SET maxdimension = GetMaxDimension(ourgeometry), lat = ST_Y(ST_Centroid(ourgeometry)), lng = ST_X(ST_Centroid(ourgeometry)) WHERE id = {$this->id};");

                    if (PGSQLHOST) {
                        # Copy into Postgres.  Continue if we don't manage to connect - background cron will save us.
                        $pgsql = new LoggedPDO(PGSQLHOST, PGSQLDB, PGSQLUSER, PGSQLPASSWORD, FALSE, NULL, 'pgsql');
                        $pgsql->allDownRetries = 0;

                        if ($pgsql) {
                            $pgsql->preExec("INSERT INTO locations (locationid, name, type, area, location)
                        VALUES (?, ?, ?, ST_Area(ST_GeomFromText(?, {$this->dbhr->SRID()})), ST_GeomFromText(?, {$this->dbhr->SRID()}))
                        ON CONFLICT(locationid) DO UPDATE SET location = ST_GeomFromText(?, {$this->dbhr->SRID()});", [
                                $this->id, $this->loc['name'], $this->loc['type'], $val, $val, $val
                            ]);

                            if ($oldval[0]['unioned']) {
                                # We want to remap the areas.  A common case is when we are tweaking areas, and therefore the
                                # old and new value will overlap.  We can save time by only mapping the union.
                                $this->remapPostcodes($oldval[0]['unioned']);
                            } else {
                                # They are completely separate.  Remap both.
                                $this->remapPostcodes($oldval[0]['geometry']);
                                $this->remapPostcodes($val);
                            }
                        }
                    }

                    # This will not be a complete remapping.  We might have postcodes which are near the old value but
                    # not inside, and those should be remapped.  This is a less important case to do in real time and
                    # is handled by a cron job.
                }
            }
        }

        return($rc);
    }

    public function convexHull($points) {
        $mp = new \MultiPoint($points);
        $hull = $mp->convexHull();
        return $hull;
    }

    public function inventArea($areaid) {
        #  Invent our best guess based on the convex hull of the postcodes which we have
        # decided are in this area.
        $g = new \geoPHP();
        $pcs = $this->dbhr->preQuery("SELECT * FROM locations WHERE areaid = ?;", [$areaid]);
        $points = [];
        foreach ($pcs as $pc) {
            $pstr = "POINT({$pc['lng']} {$pc['lat']})";
            #error_log("...{$pc['name']} $pstr");
            $points[] = $g::load($pstr);
        }

        $hull = $this->convexHull($points);

        # We might not get a hull back, because it relies on a PHP extension.
        $geom = $hull ? $hull->asText() : NULL;
        #error_log("Set geom $geom");

        if ($geom) {
            $thisone['polygon'] = $geom;

            # Save it for next time.
            $this->dbhm->preExec("UPDATE locations SET ourgeometry = ST_GeomFromText(?, {$this->dbhr->SRID()}) WHERE id = ?;", [
                $geom,
                $areaid
            ]);

            $this->dbhm->preExec("REPLACE INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, {$this->dbhr->SRID()}));", [
                $areaid,
                $geom
            ]);
        }

        return($geom);
    }

    public function withinBox($swlat, $swlng, $nelat, $nelng) {
        # Return the areas within the box which are used for postcodes, along with a polygon which shows their shape.
        #  This allows us to display our areas on a map.  Put a limit on this so that the API can't kill us.
        #
        # Simplify it - taking care as ST_Simplify can fail.
        $sql = "SELECT DISTINCT l.*,
            ST_AsText( 
                CASE WHEN ST_Simplify(CASE WHEN l.ourgeometry IS NOT NULL THEN l.ourgeometry ELSE l.geometry END, ?) IS NULL
                THEN 
                   CASE WHEN l.ourgeometry IS NOT NULL THEN l.ourgeometry ELSE l.geometry END
                ELSE   
                    ST_Simplify(CASE WHEN l.ourgeometry IS NOT NULL THEN l.ourgeometry ELSE l.geometry END, ?)
                END
            ) AS geom
            FROM
                 (SELECT locationid FROM locations_spatial
                     INNER JOIN locations l2 on l2.areaid = locations_spatial.locationid         
                     WHERE ST_Intersects(locations_spatial.geometry,
                    ST_GeomFromText('POLYGON(($swlng $swlat, $nelng $swlat, $nelng $nelat, $swlng $nelat, $swlng $swlat))', {$this->dbhr->SRID()}))
                     AND l2.type = 'Postcode'
                 ) ls
            INNER JOIN locations l ON l.id = ls.locationid  
            LEFT JOIN locations_excluded ON ls.locationid = locations_excluded.locationid
            WHERE locations_excluded.locationid IS NULL
            LIMIT 500;";

        #file_put_contents('/tmp/sql', $sql);
        $areas = $this->dbhr->preQuery($sql, [ LoggedPDO::SIMPLIFY, LoggedPDO::SIMPLIFY ]);
        $ret = [];

        foreach ($areas as $area) {
            $thisone = $area;
            $thisone['polygon'] = NULL;
            $thisone['geometry'] = NULL;
            $thisone['ourgeometry'] = NULL;

            $geom = $area['geom'];

            if (substr($geom, 0, 6) == 'POINT(') {
                # Point location.  Return a basic polygon to make it visible and editable.
                $swlat = $thisone['lat'] - 0.0005;
                $swlng = $thisone['lng'] - 0.0005;
                $nelat = $thisone['lat'] + 0.0005;
                $nelng = $thisone['lng'] + 0.0005;
                $geom = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
            }

            #error_log("For {$area['areaid']} {$thisone['name']} geom $geom");

            if (substr($geom, 0, 7) != 'POLYGON') {
                # We don't have a polygon for this area.  This is common for OSM data, where many towns etc are just
                # recorded as points.
                $geom = $this->inventArea($area['id']);
            }

            $thisone['polygon'] = $geom;
            $thisone['geom'] = NULL;

            # Get the top-level postcode.
            $tpcid = $area['postcodeid'];
            #error_log("Postcode $tpcid for " . $a->getPrivate('name'));

            if ($tpcid) {
                $tpc = new Location($this->dbhr, $this->dbhm, $tpcid);
                $thisone['postcode'] = $tpc->getPublic();
            }

            $ret[] = $thisone;
        }

        return($ret);
    }

    public function ensureVague()
    {
        $ret = $this->loc['name'];
        $p = strpos($ret, ' ');

        if ($this->loc['type'] == 'Postcode' && $p !== FALSE) {
            $ret = substr($ret, 0, $p);
        }

        return($ret);
    }

    public function getByIds($locids, &$locationlist) {
        # Efficiently get a bunch of locations.
        $locids = array_unique(array_diff($locids, array_filter(array_column($locationlist, 'id'))));

        if (count($locids)) {
            $sql = "SELECT " . implode(',', $this->publicatts) . " FROM locations WHERE id IN (" . implode(',', $locids) . ");";
            $fetches = $this->dbhr->preQuery($sql);
            $others = [];

            foreach ($fetches as $fetch) {
                $locationlist[$fetch['id']] = new Location($this->dbhr, $this->dbhm, $fetch['id'], $fetch);
                $others[] = Utils::pres('areaid', $fetch);
                $others[] = Utils::pres('postcodeid', $fetch);
            }

            # Now get the postcode and area locations.
            $others = array_unique(array_filter($others));

            if (count($others)) {
                $sql = "SELECT " . implode(',', $this->publicatts) . " FROM locations WHERE id IN (" . implode(',', $others) . ");";
                $fetches = $this->dbhr->preQuery($sql);

                foreach ($fetches as $fetch) {
                    $locationlist[$fetch['id']] = new Location($this->dbhr, $this->dbhm, $fetch['id'], $fetch);
                }
            }
        }
    }

    public function copyLocationsToPostgresql($trans = TRUE) {
        $count = 0;

        if (PGSQLHOST) {
            # We make limited use of Postgresql, because Postgis is fab. This method copies all relevant locations from
            # the locations table into Postgresql.  We try to keep the Postgresql table in sync in setGeometry, but
            # doing this full copy regularly is a safety net.
            $pgsql = new LoggedPDO(PGSQLHOST, PGSQLDB, PGSQLUSER, PGSQLPASSWORD, FALSE, NULL, 'pgsql');
            $pgsql->allDownRetries = 0;

            # When running on Docker/CircleCI, the database is not set up fully.
            $pgsql->preExec("CREATE EXTENSION IF NOT EXISTS postgis;");
            $pgsql->preExec("CREATE EXTENSION IF NOT EXISTS btree_gist;");

            # We use a tmp table.  This can mean that any location changes which happen during this process will not
            # get picked up until the next time we do this processing.
            $uniq = uniqid('_');
            $pgsql->preExec("DROP TABLE IF EXISTS locations_tmp$uniq;");
            $pgsql->preExec("DROP INDEX IF EXISTS idx_location$uniq;");
            $pgsql->preExec("DROP INDEX IF EXISTS idx_location_id$uniq;");
            try {
                # No easy way to CREATE TYPE IF NOT EXISTS.
                $this->dbhm->suppressSentry = TRUE;
                $pgsql->preExec("CREATE TYPE location_type AS ENUM('Road','Polygon','Line','Point','Postcode');");
            } catch (\Exception $e) {}
            $this->dbhm->suppressSentry = FALSE;

            $pgsql->preExec("CREATE TABLE locations_tmp$uniq (id serial, locationid bigint, name text, type location_type, area numeric, location geometry);");
            $pgsql->preExec("ALTER TABLE locations_tmp$uniq SET UNLOGGED");

            # Get the locations.  Go direct to PDO as we want an unbuffered query to reduce memory usage.
            $this->dbhr->doConnect();

            # Get non-excluded polygons.
            $locations = $this->dbhr->_db->query("SELECT locations.id, name, type, 
               ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geom
               FROM locations LEFT JOIN locations_excluded le on locations.id = le.locationid 
               WHERE le.locationid IS NULL AND ST_Dimension(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) = 2 AND type != 'Postcode';");

            $count = 0;
            foreach ($locations as $location) {
                $pgsql->preExec("INSERT INTO locations_tmp$uniq (locationid, name, type, area, location) VALUES (?, ?, ?, ST_Area(ST_GeomFromText(?, {$this->dbhr->SRID()})), ST_GeomFromText(?, {$this->dbhr->SRID()}));", [
                    $location['id'], $location['name'], $location['type'], $location['geom'], $location['geom']
                ]);

                $count++;

                if ($count % 1000 == 0) {
                    error_log("...added $count");
                }
            }

            $pgsql->preExec("CREATE INDEX idx_location$uniq ON locations_tmp$uniq USING gist(location);");
            $pgsql->preExec("CREATE UNIQUE INDEX idx_locationid$uniq ON locations_tmp$uniq USING BTREE(locationid);");
            $pgsql->preExec("ALTER TABLE locations_tmp$uniq SET LOGGED");

            # Atomic swap of tables.
            #
            # There's something weird on CircleCI where this doesn't work inside a transaction, but it does on our
            # live system.  We don't really care about transactions on there so hack around it.
            $pgsql->preExec("CREATE TABLE IF NOT EXISTS locations (LIKE locations_tmp$uniq);");
            if ($trans) {
                $pgsql->preExec("BEGIN;");
            }
            $pgsql->preExec("ALTER TABLE locations RENAME TO locations_old$uniq;");
            $pgsql->preExec("ALTER TABLE locations_tmp$uniq RENAME TO locations;");
            $pgsql->preExec("DROP TABLE locations_old$uniq;");

            if ($trans) {
                $pgsql->preExec("COMMIT;");
            }
        }

        return $count;
    }
    
    public function remapPostcodes($geom = NULL) {
        $count = 0;

        if (PGSQLHOST) {
            # We make use of Postgresql for this, because Postgis is fab. This method maps all the postcodes to
            # locations using the data in Postgresql.
            #
            # This method assumes that copyLocationsToPostgresql has been called.
            $pgsql = new LoggedPDO(PGSQLHOST, PGSQLDB, PGSQLUSER, PGSQLPASSWORD, FALSE, NULL, 'pgsql');
            $pgsql->allDownRetries = 0;
            $geomq = $geom ? " ST_Contains(ST_GeomFromText('$geom', {$this->dbhr->SRID()}), locations_spatial.geometry) AND " : '';

            $pcs = $this->dbhr->preQuery("SELECT DISTINCT locations_spatial.locationid, locations.name, locations.lat, locations.lng, locations.name AS areaname, locations.id AS areaid FROM locations_spatial 
    INNER JOIN locations ON locations_spatial.locationid = locations.id
    WHERE $geomq locations.type = 'Postcode'  
    AND locate(' ', locations.name) > 0
    ;");

            $total = count($pcs);

            foreach ($pcs as $pc) {
                // This query is the heart of things.  Postgis allows us to find KNN efficiently.  We use that to
                // find a bunch of nearby candidate locations.  Then we simulate the algorithm we used to use, of
                // having a small box which gradually increases in size until it finds us a location, by
                // having a bunch of ST_Intersects queries.  This is well-indexed too.  Then we can choose the
                // smallest of these.
                $pgareas = $pgsql->preQuery("
WITH ourpoint AS
(
 SELECT ST_MakePoint(?, ?) as p
)
SELECT
   locationid,
   name,
   ST_Area(location) AS area,
   dist,
   CASE
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.00015625), 3857)) THEN 1
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.0003125), 3857)) THEN 2
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.000625), 3857)) THEN 3
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.00125), 3857)) THEN 4
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.0025), 3857)) THEN 5
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.005), 3857)) THEN 6
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.01), 3857)) THEN 7
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.02), 3857)) THEN 8
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.04), 3857)) THEN 9
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.08), 3857)) THEN 10
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.16), 3857)) THEN 11
       WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer((SELECT p FROM ourpoint),0.32), 3857)) THEN 12
   END AS intersects
  FROM (
    SELECT   locationid,
             name,
             location,
             location <-> ST_SetSRID((SELECT p FROM ourpoint), 3857) AS dist
    FROM     locations 
    WHERE    ST_Area(location) BETWEEN 0.00001 AND 0.15
    ORDER BY location <-> ST_SetSRID((SELECT p FROM ourpoint), 3857)
    LIMIT 10
) q
ORDER BY intersects ASC, area ASC LIMIT 1;
", [
                    $pc['lng'],
                    $pc['lat'],
                ]);

                if (count($pgareas)) {
                    $pgarea = $pgareas[0];
                    #error_log("Mapped {$pc['name']} to {$pgarea['name']}, {$pgarea['locationid']} vs {$pc['areaid']}");

                    if ($pgarea['locationid'] != $pc['areaid']) {
                        #error_log("Set area {$pgarea['locationid']} in {$pc['locationid']}");
                        $this->dbhm->preExec("UPDATE locations SET areaid = ? WHERE id = ?", [
                            $pgarea['locationid'],
                            $pc['locationid']
                        ]);
                    }
                } else {
                    error_log("#{$pc['locationid']} {$pc['name']} {$pc['lat']}, {$pc['lng']} = {$pc['areaid']} {$pc['areaname']} => not mapped");
                }

                $count++;

                if ($count % 1000 == 0) {
                    error_log("...$count / $total");
                }
            }
        }

        return $count;
    }
}