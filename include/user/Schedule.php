<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/user/User.php');

class Schedule extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'created', 'schedule', 'userid');
    var $settableatts = array('created', 'schedule');
    protected $schedule = NULL;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $userid = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->fetch($dbhr, $dbhm, NULL, 'users_schedules', 'schedule', $this->publicatts);

        if ($userid) {
            $schedules = $this->dbhr->preQuery("SELECT id FROM users_schedules WHERE userid = ?;", [
                $userid
            ]);

            foreach ($schedules as $schedule) {
                $this->fetch($dbhr, $dbhm, $schedule['id'], 'users_schedules', 'schedule', $this->publicatts);
            }
        }
    }

    public function create($userid, $schedule) {
        $id = NULL;

        $rc = $this->dbhm->preExec("REPLACE INTO users_schedules (userid, schedule) VALUES (?, ?);", [
            $userid,
            json_encode($schedule)
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            if ($id) {
                $this->fetch($this->dbhm, $this->dbhm, $id, 'users_schedules', 'schedule', $this->publicatts);
            }
        }

        return($id);
    }

    public function getPublic()
    {
        $ret = parent::getPublic();
        $ret['schedule'] = json_decode($ret['schedule'], TRUE);
        $ret['created'] = pres('created', $ret) ? ISODate($ret['created']) : NULL;

        return($ret);
    }

    public function setSchedule($schedule) {
        $this->setPrivate('schedule', json_encode($schedule));
    }

    public function match($user1, $user2) {
        $schedules = $this->dbhr->preQuery("SELECT * FROM users_schedules WHERE userid = ? OR userid = ?;", [
            $user1,
            $user2
        ]);

        $matches = [];

        if (count($schedules) == 2) {
            $schedule1 = json_decode($schedules[0]['schedule'], TRUE);
            $schedule2 = json_decode($schedules[1]['schedule'], TRUE);

            foreach ($schedule1 as $slot1) {
                foreach ($schedule2 as $slot2) {
                    #error_log("Compare {$slot1['date']} {$slot1['hour']} av {$slot1['available']} to {$slot2['date']} {$slot2['hour']} av {$slot2['available']} ");
                    $key = $slot1['date'] . $slot1['hour'];

                    if ($slot1['available'] && $slot2['available'] &&
                        $slot1['date'] == $slot2['date'] &&
                        $slot1['hour'] == $slot2['hour'] &&
                        !array_key_exists($key, $matches)) {
                        $matches[$key] = $slot1;
                        #error_log("Matches {$slot1['date']} {$slot1['hour']} av {$slot1['available']} to {$slot2['date']} {$slot2['hour']} av {$slot2['available']} ");
                    }
                }
            }
        }

        ksort($matches);

        return(array_values($matches));
    }
}