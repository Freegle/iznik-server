<?php
namespace Freegle\Iznik;

function giftaid() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $me = Session::whoAmI($dbhr, $dbhm);

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];
            $all = array_key_exists('all', $_REQUEST) ? filter_var($_REQUEST['all'], FILTER_VALIDATE_BOOLEAN) : FALSE;

            if ($me) {
                $d = new Donations($dbhr, $dbhm);

                if ($all && ($me->isAdmin() || $me->hasPermission(User::PERM_GIFTAID))) {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'giftaids' => $d->listGiftAidReview($me->getId())
                    ];
                } else {
                    # Just get ours
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'giftaid' => $d->getGiftAid($me->getId())
                    ];
                }
            }

            break;
        }

        case 'POST': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $d = new Donations($dbhr, $dbhm);
                $period = Utils::presdef('period', $_REQUEST, NULL);
                $fullname = Utils::presdef('fullname', $_REQUEST, NULL);
                $homeaddress = Utils::presdef('homeaddress', $_REQUEST, NULL);

                $ret = ['ret' => 2, 'status' => 'Bad parameters'];

                if ($period && $fullname && $homeaddress) {
                    $id = $d->setGiftAid($me->getId(), $period, $fullname, $homeaddress);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'id' => $id
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

        case 'PATCH': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me && ($me->isAdmin() || $me->hasPermission(User::PERM_GIFTAID))) {
                $id = (Utils::presint('id', $_REQUEST, 0));
                $period = Utils::presdef('period', $_REQUEST, NULL);
                $fullname = Utils::presdef('fullname', $_REQUEST, NULL);
                $homeaddress = Utils::presdef('homeaddress', $_REQUEST, NULL);
                $postcode = Utils::presdef('postcode', $_REQUEST, NULL);
                $housenameornumber = Utils::presdef('housenameornumber', $_REQUEST, NULL);
                $reviewed = array_key_exists('reviewed', $_REQUEST) ? filter_var($_REQUEST['reviewed'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                $deleted = array_key_exists('deleted', $_REQUEST) ? filter_var($_REQUEST['deleted'], FILTER_VALIDATE_BOOLEAN) : FALSE;

                $d = new Donations($dbhr, $dbhm);
                $d->editGiftAid($id, $period, $fullname, $homeaddress, $postcode, $housenameornumber, $reviewed, $deleted);

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
