<?php
namespace Freegle\Iznik;

function dashboard() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);
    $systemwide = array_key_exists('systemwide', $_REQUEST) ? filter_var($_REQUEST['systemwide'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $force = array_key_exists('force', $_REQUEST) ? filter_var($_REQUEST['force'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $allgroups = array_key_exists('allgroups', $_REQUEST) ? filter_var($_REQUEST['allgroups'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $heatmap = array_key_exists('heatmap', $_REQUEST) ? filter_var($_REQUEST['heatmap'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $groupid = presdef('group', $_REQUEST, NULL);
    $groupid = $groupid ? intval($groupid) : NULL;
    $type = presdef('grouptype', $_REQUEST, NULL);
    $start = presdef('start', $_REQUEST, '30 days ago');
    $end = presdef('end', $_REQUEST, 'today');
    $region = presdef('region', $_REQUEST, NULL);
    $components = presdef('components', $_REQUEST, NULL);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = array('ret' => 0, 'status' => 'Success');
            $d = new Dashboard($dbhr, $dbhm, $me);

            if ($components) {
                # Newer style dashboard has multiple components which we can request some or all of.
                $ret['components'] = $d->getComponents($components, $systemwide, $allgroups, $groupid, $region, $type, $start, $end, $force);
            } else {
                # Older style dashboard for old ModTools and stats.
                $ret['dashboard'] = $d->get($systemwide, $allgroups, $groupid, $region, $type, $start, $end, $force);
                $s = new Stats($dbhr, $dbhm);
                $ret['heatmap'] = $heatmap ? $s->getHeatmap() : NULL;

                $ret['emailproblems'] = $dbhr->preQuery("SELECT * FROM `domains` WHERE problem = 1 ORDER BY domain ASC;");
                foreach ($ret['emailproblems'] as &$domain) {
                    $domain['timestamp'] = ISODate($domain['timestamp']);
                }
            }

            $ret['start'] = $start;
            $ret['end'] = $end;

            break;
        }
    }

    return($ret);
}
