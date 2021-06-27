<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

class Authority extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'name', 'area_code', 'simplified');

    /** @var  $log Log */
    var $auth;

    # Friendly names for area codes as defined by OS.
    private $area_codes = [
        'CUN' => 'Country', // Not an OS code
        'CTY' => 'County Council',
        'CED' => 'County Electoral Division',
        'DIS' => 'District Council',
        'DIW' => 'District Ward',
        'EUR' => 'European Region',
        'GLA' => 'Greater London Authority',
        'LAC' => 'Greater London Authority Assembly Constituency',
        'LBO' => 'London Borough',
        'LBW' => 'London Borough Ward',
        'MTD' => 'Metropolitan District',
        'MTW' => 'Metropolitan District Ward',
        'SPE' => 'Scottish Parliament Electoral Region',
        'SPC' => 'Scottish Parliament Constituency',
        'UTA' => 'Unitary Authority',
        'UTE' => 'Unitary Authority Electoral Division',
        'UTW' => 'Unitary Authority Ward',
        'WAE' => 'Welsh Assembly Electoral Region',
        'WAC' => 'Welsh Assembly Constituency',
        'WMC' => 'Westminster Constituency',
        'WST' => 'Waste Authority'
    ];

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'authorities', 'auth', $this->publicatts);
    }

    public function create($name, $area_code, $polygon) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO authorities (name, area_code, polygon) VALUES (?,?,ST_GeomFromText(?)) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), polygon = ST_GeomFromText(?);", [
            $name,
            $area_code,
            $polygon,
            $polygon
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            if ($id) {
                try {
                    # The simplify call may fail.  We've seen this where there is a multipolygon, and the simplify
                    # returns a polygon with only two vertices, which then fails to update because it's invalid as
                    # a polygon.  So we do it separately and catch the exception.
                    $this->dbhm->preExec("UPDATE authorities SET simplified = ST_Simplify(ST_GeomFromText(polygon), 0.001) WHERE id = ?;", [
                        $id
                    ]);
                } catch (\Exception $e) {}

                $this->fetch($this->dbhm, $this->dbhm, $id, 'authorities', 'auth', $this->publicatts);
            }
        }

        return($id);
    }

    public function search($term, $limit = 10) {
        # Remove any weird characters.
        $limit = intval($limit);
        $term = preg_replace("/[^[:alnum:][:space:]]/u", '', $term);

        $auths = $this->dbhr->preQuery("SELECT id, name, area_code FROM authorities WHERE name LIKE " . $this->dbhr->quote("%$term%") . " LIMIT $limit;");

        foreach ($auths as &$auth) {
            $auth['area_code'] = Utils::pres($auth['area_code'], $this->area_codes) ? $this->area_codes[$auth['area_code']] : NULL;
        }

        return($auths);
    }

    public function getPublic()
    {
        $auths = $this->dbhr->preQuery("SELECT id, name, area_code,  ST_AsText(COALESCE(simplified, polygon)) AS polygon, ST_Y(ST_CENTROID(polygon)) AS lat, ST_X(ST_CENTROID(polygon)) AS lng FROM authorities WHERE id = ?;", [
            $this->id
        ]);

        $atts = $auths[0];

        # Map the area code to something friendly.
        $atts['area_code'] = Utils::pres($atts['area_code'], $this->area_codes) ? $this->area_codes[$atts['area_code']] : NULL;

        # Return the centre.
        $atts['centre'] = [
            'lat' => $atts['lat'],
            'lng' => $atts['lng']
        ];
        unset($atts['lat']);
        unset($atts['lng']);

        # Find groups which overlap with this area.
        $sql = "SELECT groups.id, nameshort, namefull, lat, lng, 
       CASE WHEN poly IS NOT NULL THEN poly ELSE polyofficial END AS poly, 
       CASE 
         WHEN polyindex = 
              Coalesce(simplified, polygon) THEN 1 
         ELSE St_area(St_intersection(polyindex, 
                                     Coalesce(simplified, polygon))) 
              / St_area(polyindex) 
       end                          AS overlap, 
       CASE 
         WHEN polyindex = 
              Coalesce(simplified, polygon) THEN 1 
         ELSE St_area(polyindex) / St_area( 
                     St_intersection(polyindex, 
                             Coalesce(simplified, polygon))) 
       end                          AS overlap2 
FROM   `groups` 
       INNER JOIN authorities 
               ON ( polyindex = 
                    Coalesce(simplified, polygon) 
                     OR St_intersects(polyindex, 
                            Coalesce(simplified, polygon)) ) 
WHERE  type = ? 
       AND publish = 1 
       AND onmap = 1 
       AND authorities.id = ?;";
        #error_log("Overlap SQL $sql, {$atts['id']}");
        $groups = $this->dbhr->preQuery($sql, [
            Group::GROUP_FREEGLE,
            $atts['id']
        ]);

        $ret = [];

        foreach ($groups as &$group) {
            $group['namedisplay'] = Utils::pres('namefull', $group) ? $group['namefull'] : $group['nameshort'];

            if ($group['overlap'] > 0.95) {
                # Assume it's basically all aimed at this area.
                $group['overlap'] = 1;
            }

            if ($group['overlap'] >= 0.05 || $group['overlap2'] >= 0.05) {
                # Exclude - minor overlaps.
                $ret[] = $group;
            }
        }

        $atts['groups'] = $ret;

        return($atts);
    }

    public function contains($lat, $lng) {
        $auths = $this->dbhr->preQuery("SELECT id FROM authorities WHERE id = ? AND ST_Contains(polygon, POINT(?,?));", [
            $this->id,
            $lng,
            $lat
        ]);

        return count($auths) == 1;
    }
}