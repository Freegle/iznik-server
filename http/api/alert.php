<?php
namespace Freegle\Iznik;

function alert() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = Utils::presint('id', $_REQUEST, NULL);
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
                    $trackid = (Utils::presint('trackid', $_REQUEST, NULL));
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
                $groupid = Utils::presint('groupid', $_REQUEST, NULL);
                $groupid = $groupid == 'AllFreegle' ? NULL : intval($groupid);
                $askclick = Utils::presint('askclick', $_REQUEST, 1);
                $tryhard = Utils::presint('tryhard', $_REQUEST, 1);
                
                $alertid = $a->create($groupid, $from, $to, $subject, $text, $html, $askclick, $tryhard);

                $ret = $alertid ? ['ret' => 0, 'status' => 'Success', 'id' => $alertid] : ['ret' => 2, 'status' => 'Create failed'];
            }
        }
    }

    return($ret);
}
