<?php
function domains() {
    global $dbhr, $dbhm;

    $domain = presdef('domain', $_REQUEST, NULL);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($domain) {
                $ret = [ 'ret' => 0, 'status' => 'Success' ];

                $domains = $dbhr->preQuery("SELECT id FROM domains_common WHERE domain LIKE ?;", [
                    $domain
                ]);

                if (count($domains) === 0) {
                    # This is not a common domain.  It may be a typo.  See if there are suggestions we can make.,
                    $sql = "SELECT * FROM domains_common WHERE damlevlim(`domain`, ?, " . strlen($domain) . ") < 3 ORDER BY count DESC LIMIT 5;";
                    error_log($sql);
                    $suggestions = $dbhr->preQuery($sql, [ $domain ]);

                    $ret['suggestions'] = [];
                    foreach ($suggestions as $s) {
                        $ret['suggestions'][] = $s['domain'];
                    }
                }
            }
            break;
        }
    }

    return($ret);
}
