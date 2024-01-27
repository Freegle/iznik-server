<?php
# Experiment with identifying postal addresses in chat.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$mysqltime = date ("Y-m-d", strtotime("Midnight 31 days ago"));

$msgs = $dbhr->preQuery("SELECT * FROM chat_messages WHERE date > ? AND type = ? AND message like '%address is%' ORDER BY date DESC LIMIT 1000", [
    $mysqltime,
    ChatMessage::TYPE_DEFAULT
]);

$addrs = [];
$poss = 0;
$found = 0;
$nooffer = 0;
$faraway = 0;
$nostreet = 0;

function levensteinSubstringContains($needle, $haystack, $maximalDistance = 2, $caseInsensitive = true)
{
    $lengthNeedle = strlen($needle);
    $lengthHaystack = strlen($haystack);

    if ($caseInsensitive) {
        $needle = strtolower($needle);
        $haystack = strtolower($haystack);
    }

    if ($lengthNeedle > $lengthHaystack) {
        return false;
    }

    if (false !== strpos($haystack, $needle)) {

        return true;
    }

    $i = 0;
    while (($i + $lengthNeedle) <= $lengthHaystack) {
        $comparePart = substr($haystack, $i, $lengthNeedle);

        $levenshteinDistance = levenshtein($needle, $comparePart);
        if ($levenshteinDistance <= $maximalDistance) {

            return true;
        }
        $i++;
    }

    return false;
}

/**
 * @param mixed $msg
 * @param $matches
 * @param $dbhr
 * @param $dbhm
 * @param int $faraway
 * @param int $found
 * @param int $nostreet
 * @return array
 */
function extracted(mixed $msg, $matches, $dbhr, $dbhm, int $faraway, int $found, int $nostreet): array
{
    if (preg_match(Utils::POSTCODE_PATTERN, $msg['message'], $matches))
    {
        $pc = strtoupper($matches[0]);
        error_log("...postcode $pc");
        $l = new Location($dbhr, $dbhm);

        $locs = $dbhr->preQuery("SELECT * FROM locations WHERE canon = ?", [
            $l->canon($pc)
        ]);

        if (count($locs))
        {
            $loc = $locs[0];

            # Check it's not too far away.
            $u = User::get($dbhr, $dbhm, $msg['userid']);
            list ($lat, $lng, $loc2) = $u->getLatLng();

            $dist = \GreatCircle::getDistance($lat, $lng, $loc['lat'], $loc['lng']);
            error_log("Distance away $dist");

            if ($dist > 20000)
            {
                error_log("...too far away $dist");
                $faraway++;
            } else
            {
                # Found it.  Check that we have the street name in there too to avoid the possibility of us
                # just sending the postcode.
                $streets = $dbhr->preQuery(
                    "SELECT DISTINCT thoroughfaredescriptor FROM paf_thoroughfaredescriptor INNER JOIN paf_addresses ON paf_addresses.thoroughfaredescriptorid = paf_thoroughfaredescriptor.id WHERE paf_addresses.postcodeid = ?",
                    [
                        $loc['id']
                    ]
                );

                error_log("...check streets");

                $foundIt = false;

                foreach ($streets as $street)
                {
                    if (levensteinSubstringContains($street['thoroughfaredescriptor'], $msg['message'], 3))
                    {
                        $foundIt = true;
                        break;
                    }
                }

                if ($foundIt)
                {
                    error_log("...found location {$loc['id']} {$loc['name']}");
                    $found++;
                } else
                {
                    error_log("...didn't find street");
                    $nostreet++;
                }
            }
        } else
        {
            error_log("...couldn't find location from postcode $pc");
        }
    }
    return array($matches, $faraway, $found, $nostreet);
}

foreach ($msgs as $msg) {
    # See whether the userid in this message has recently received a reply about an OFFER.
    error_log("{$msg['id']} {$msg['message']}");
    $poss++;
    $recent = date ("Y-m-d", strtotime("Midnight 7 days ago"));
    $interested = $dbhr->preQuery("SELECT * FROM chat_messages WHERE chatid = ? AND userid != ? AND type = ? AND date > ? ORDER BY date DESC LIMIT 1", [
        $msg['chatid'],
        $msg['userid'],
        ChatMessage::TYPE_INTERESTED,
        $recent
    ]);

    list($matches, $faraway, $found, $nostreet) = extracted($msg, $matches, $dbhr, $dbhm, $faraway, $found, $nostreet);


    # Try geocoding within 10 miles of the location.
//            $j = new Jobs($dbhr, $dbhm);
//
//            error_log("...user is at $lat, $lng");
//            $distance = 10 * 1609.34;
//            $ne = \GreatCircle::getPositionByDistance($distance, 45, $lat, $lng);
//            $sw = \GreatCircle::getPositionByDistance($distance, 255, $lat, $lng);
//
//            list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = $j->geocode($addr, TRUE, FALSE, $sw['lat'], $sw['lng'], $ne['lat'], $ne['lng']);
//
//            if ($swlat !== NULL) {
//                error_log("...found address $addr at $swlat, $swlng to $nelat, $nelng");
//                $found++;
//            } else {
//                error_log("...couldn't find address $addr in [[{$sw['lat']}, {$sw['lng']}], [{$ne['lat']}, {$ne['lng']}]]");
//            }
//        }
//    }
}

error_log("\r\n\r\n$poss possible addresses, $found found, $nooffer no offer, $faraway too far away, $nostreet no street");
