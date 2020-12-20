<?php
namespace Freegle\Iznik;

function schedule() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $me = Session::whoAmI($dbhr, $dbhm);
    $myid = $me ? $me->getId() : NULL;
    $userid = (Utils::presint('userid', $_REQUEST, NULL));
    $chatuserid = (Utils::presint('chatuserid', $_REQUEST, NULL));
    $allowpast = array_key_exists('allowpast', $_REQUEST) ? filter_var($_REQUEST['allowpast'], FILTER_VALIDATE_BOOLEAN) : FALSE;

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        switch ($_REQUEST['type']) {
            case 'GET': {
                # Once you're logged in, you can see other user's schedules.
                $s = new Schedule($dbhr, $dbhm, $userid ? $userid : $myid, $allowpast);
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'schedule' => $s->getPublic()
                ];

                break;
            }

            case 'POST':
                $s = new Schedule($dbhr, $dbhm, $myid, $allowpast);
                $id = $s->create($me->getId(), Utils::presdef('schedule', $_REQUEST, NULL));

                if ($chatuserid) {
                    # We are updating a schedule from within a chat to another user.  Create a message
                    # between the users to show that we have created this schedule.
                    $r = new ChatRoom($dbhr, $dbhm);
                    $rid = $r->createConversation($myid, $chatuserid);
                    $m = new ChatMessage($dbhr, $dbhm);
                    list ($mid, $banned) = $m->create($rid, $myid, NULL, ChatMessage::TYPE_SCHEDULE, NULL, TRUE, NULL, NULL, NULL, NULL, NULL);
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $id
                ];
                break;

            case 'PATCH':
            case 'PUT': {
                $s = new Schedule($dbhr, $dbhm, $myid, $allowpast);
                $s->setSchedule(Utils::presdef('schedule', $_REQUEST, NULL));

                if ($chatuserid) {
                    # Create a message in a chat between the users to show that we have updated this schedule.
                    $r = new ChatRoom($dbhr, $dbhm);
                    $rid = $r->createConversation($myid, $chatuserid);
                    $m = new ChatMessage($dbhr, $dbhm);
                    list ($mid, $banned) = $m->create($rid, $myid, NULL, ChatMessage::TYPE_SCHEDULE_UPDATED, NULL, TRUE, NULL, NULL, NULL, NULL, NULL);
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success'
                ];
                break;
            }
        }
    }

    return($ret);
}
