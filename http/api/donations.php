<?php
namespace Freegle\Iznik;

function donations() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $me = Session::whoAmI($dbhr, $dbhm);
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));

    switch ($_REQUEST['type']) {
        case 'GET': {
            $d = new Donations($dbhr, $dbhm, $groupid);

            $ret = [
                'ret' => 1,
                'status' => 'Permission denied'
            ];

            if ($me && $me->hasPermission(User::PERM_GIFTAID)) {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'donations' => $d->get()
                ];
            }
            break;
        }

        case 'PUT': {
            $d = new Donations($dbhr, $dbhm, $groupid);
            $uid = Utils::presint('userid', $_REQUEST, NULL);
            $amount = Utils::presfloat('amount', $_REQUEST, 0);
            $date = Utils::presdef('date', $_REQUEST, NULL);

            $ret = [
                'ret' => 1,
                'status' => 'Permission denied or invalid parameters'
            ];

            if ($me && $me->hasPermission(User::PERM_GIFTAID) && $uid && $amount && $date) {
                $u = User::get($dbhr, $dbhm, $uid);

                $ret = [
                    'ret' => 2,
                    'status' => 'Invalid userid'
                ];

                if ($u->getId() == $uid) {
                    $id = $d->add($uid, $u->getEmailPreferred(), $u->getName(), $date, 'External added at ' . date("Y-m-d H:i:s", time()), $amount, Donations::TYPE_EXTERNAL);

                    $ret = [
                        'ret' => 3,
                        'status' => 'Add failed'
                    ];

                    if ($id) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'id' => $id
                        ];
                    }
                }
            }
        }
    }

    return($ret);
}
