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
            $g = new Group($dbhr, $dbhm);

            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'socialactions' => $actions,
                'popularposts' => $g->getPopularMessages(),
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
                case GroupFacebook::ACTION_DO_POPULAR:
                case GroupFacebook::ACTION_HIDE_POPULAR: {
                    $groupid = Utils::presint('groupid', $_REQUEST, NULL);
                    $msgid = Utils::presint('msgid', $_REQUEST, NULL);
                    $ret = [
                        'ret' => 2,
                        'status' => 'Invalid parameters'
                    ];

                    if ($groupid && $msgid) {
                        if ($action == GroupFacebook::ACTION_DO_POPULAR) {
                            # Share it.
                            $f->sharePopularMessage($groupid, $msgid);
                        } else {
                            # Mark it as shared first to avoid duplicates.
                            $g = Group::get($dbhr, $dbhm, $groupid);
                            $g->hidPopularMessage($msgid);
                        }

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    }

                    break;
                }
            }

            break;
    }

    return ($ret);
}