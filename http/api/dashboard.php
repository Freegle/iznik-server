<?php
namespace Freegle\Iznik;

function dashboard() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);
    $systemwide = array_key_exists('systemwide', $_REQUEST) ? filter_var($_REQUEST['systemwide'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $force = array_key_exists('force', $_REQUEST) ? filter_var($_REQUEST['force'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $allgroups = array_key_exists('allgroups', $_REQUEST) ? filter_var($_REQUEST['allgroups'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $heatmap = array_key_exists('heatmap', $_REQUEST) ? filter_var($_REQUEST['heatmap'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $groupid = Utils::presint('group', $_REQUEST, NULL);
    $type = Utils::presdef('grouptype', $_REQUEST, NULL);
    $start = Utils::presdef('start', $_REQUEST, '30 days ago');
    $end = Utils::presdef('end', $_REQUEST, 'today');
    $region = Utils::presdef('region', $_REQUEST, NULL);
    $components = Utils::presdef('components', $_REQUEST, NULL);

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
                $type = Utils::presdef('maptype', $_REQUEST, Stats::HEATMAP_MESSAGES);
                $s = new Stats($dbhr, $dbhm);
                $ret['heatmap'] = $heatmap ? $s->getHeatmap($type) : NULL;

                $ret['emailproblems'] = $dbhr->preQuery("SELECT * FROM `domains` WHERE problem = 1 ORDER BY domain ASC;");
                foreach ($ret['emailproblems'] as &$domain) {
                    $domain['timestamp'] = Utils::ISODate($domain['timestamp']);
                }
            }

            $ret['start'] = $start;
            $ret['end'] = $end;

            break;
        }
    }

    return($ret);
}
