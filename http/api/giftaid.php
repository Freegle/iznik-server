<?php
function giftaid() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $me = whoAmI($dbhr, $dbhm);

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $d = new Donations($dbhr, $dbhm);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'giftaid' => $d->getGiftAid($me->getId())
                ];
            }

            break;
        }

        case 'POST': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $d = new Donations($dbhr, $dbhm);
                $period = presdef('period', $_REQUEST, NULL);
                $fullname = presdef('fullname', $_REQUEST, NULL);
                $homeaddress = presdef('homeaddress', $_REQUEST, NULL);

                $ret = ['ret' => 2, 'status' => 'Bad parameters'];

                if ($period && $fullname && $homeaddress) {
                    $d->setGiftAid($me->getId(), $period, $fullname, $homeaddress);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
            }

            break;
        }

        case 'DELETE': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $d = new Donations($dbhr, $dbhm);
                $d->deleteGiftAid($me->getId());

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
            }

            break;
        }

    }

    return($ret);
}
