<?php
namespace Freegle\Iznik;

function socialactions() {
    global $dbhr, $dbhm;

    $ctx = Utils::presdef('context', $_REQUEST, NULL);
    $id = (Utils::presint('id', $_REQUEST, NULL));
    $id = $id ? intval($id) : $id;
    $uid = (Utils::presint('uid', $_REQUEST, NULL));

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET':
            $f = new GroupFacebook($dbhr, $dbhm);
            $actions = $f->listSocialActions($ctx);
            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'socialactions' => $actions,
                'context' => $ctx
            ];
           break;

        case 'POST':
            $f = new GroupFacebook($dbhr, $dbhm, $uid);
            $action = Utils::presdef('action', $_REQUEST, GroupFacebook::ACTION_DO);

            switch ($action) {
                case GroupFacebook::ACTION_DO:
                    if ($f->performSocialAction($id)) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    } else {
                        $ret = [
                            'ret' => 2,
                            'status' => 'Failed'
                        ];
                    }
                    break;
                case GroupFacebook::ACTION_HIDE:
                    $f->hideSocialAction($id);
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                    break;
            }

            break;
    }

    return ($ret);
}