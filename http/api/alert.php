<?php
namespace Freegle\Iznik;

function alert() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = Utils::presdef('id', $_REQUEST, NULL);
    $id = $id ? intval($id) : NULL;
    $a = new Alert($dbhr, $dbhm, $id);
    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            if ($id) {
                # We're not bothered about privacy of alerts - people may not be logged in when they see them.
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'alert' => $a->getPublic()
                ];

                if ($me && $me->isAdminOrSupport()) {
                    $ret['alert']['stats'] = $a->getStats();
                }
            } else {
                # List all.
                $ret = ['ret' => 1, 'status' => 'Not logged in or can\'t do that'];

                if ($me && $me->isAdminOrSupport()) {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'alerts' => $a->getList()
                    ];
                }
            }
            break;
        }

        case 'POST': {
            $action = Utils::presdef('action', $_REQUEST, NULL);
            
            switch ($action) {
                case 'clicked': {
                    $trackid = intval(Utils::presdef('trackid', $_REQUEST, NULL));
                    $a->clicked($trackid);
                    break;
                }
            }

            $ret = ['ret' => 0, 'status' => 'Success'];
            break;
        }
        
        case 'PUT': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me && $me->isAdminOrSupport()) {
                $from = Utils::presdef('from', $_REQUEST, NULL);
                $to = Utils::presdef('to', $_REQUEST, 'Mods');
                $subject = Utils::presdef('subject', $_REQUEST, NULL);
                $text = Utils::presdef('text', $_REQUEST, NULL);
                $html = Utils::presdef('html', $_REQUEST, nl2br($text));
                $groupid = Utils::presdef('groupid', $_REQUEST, NULL);
                $groupid = $groupid == 'AllFreegle' ? NULL : intval($groupid);
                $askclick = array_key_exists('askclick', $_REQUEST) ? intval($_REQUEST['askclick']) : 1;
                $tryhard = array_key_exists('tryhard', $_REQUEST) ? intval($_REQUEST['tryhard']) : 1;
                
                $alertid = $a->create($groupid, $from, $to, $subject, $text, $html, $askclick, $tryhard);

                $ret = $alertid ? ['ret' => 0, 'status' => 'Success', 'id' => $alertid] : ['ret' => 2, 'status' => 'Create failed'];
            }
        }
    }

    return($ret);
}
