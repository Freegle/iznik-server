<?php
namespace Freegle\Iznik;

function abtest() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = (Utils::presint('id', $_REQUEST, NULL));

    $p = new Polls($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $uid = Utils::presdef('uid', $_REQUEST, NULL);
            $variants = $dbhr->preQuery("SELECT * FROM abtest WHERE uid = ? AND suggest = 1 ORDER BY rate DESC, RAND();", [
                $uid
            ]);

            # We use a bandit test so that we get the benefit of the best option, while still exploring others.
            # See http://stevehanov.ca/blog/index.php?id=132 for an example description.
            $r = Utils::randomFloat();

            if ($r < 0.1) {
                # The 10% case we choose a random one of the other options.
                $s = rand(1, count($variants) - 1);
                $variant = $variants[$s];
            } else {
                # Most of the time we choose the currently best-performing option.
                $variant = count($variants) > 0 ? $variants[0] : NULL;
            }

            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'variant' => $variant
            ];
            break;
        }
        case 'POST': {
            $uid = Utils::presdef('uid', $_REQUEST, NULL);
            $variant = Utils::presdef('variant', $_REQUEST, NULL);
            $shown = array_key_exists('shown', $_REQUEST) ? filter_var($_REQUEST['shown'], FILTER_VALIDATE_BOOLEAN) : NULL;
            $action = array_key_exists('action', $_REQUEST) ? filter_var($_REQUEST['action'], FILTER_VALIDATE_BOOLEAN) : NULL;

            // The client can decide that an action is more valuable.  In this case we weight it, which will result in
            // it getting a higher rate, and therefore being chosen more often.
            $score = (Utils::presint('score', $_REQUEST, 1));

            if ($uid && $variant) {
                if ($shown !== NULL) {
                    $sql = "INSERT INTO abtest (uid, variant, shown) VALUES (" . $dbhm->quote($uid) . ", " . $dbhm->quote($variant) . ", 1) ON DUPLICATE KEY UPDATE shown = shown + 1, rate = COALESCE(100 * action / shown, 0);";
                    $dbhm->background($sql);
                }

                if ($action !== NULL) {
                    $sql = "INSERT INTO abtest (uid, variant, action, rate) VALUES (" . $dbhm->quote($uid) . ", " . $dbhm->quote($variant) . ", $score,0) ON DUPLICATE KEY UPDATE action = action + $score, rate = COALESCE(100 * action / shown, 0);";
                    $dbhm->background($sql);
                }
            }

            $ret = [
                'ret' => 0,
                'status' => 'Success'
            ];
            break;
        }
    }

    return($ret);
}
