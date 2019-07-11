<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/user/Schedule.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class scheduleAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, 'Test User');
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u1 = User::get($this->dbhr, $this->dbhm, $uid1);
        $u2 = User::get($this->dbhr, $this->dbhm, $uid2);
        assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Create logged out - should fail
        $ret = $this->call('schedule', 'POST', [
            'schedule' => [
                'test' => 1
            ]
        ]);
        assertEquals(1, $ret['ret']);

        $schedule = [
            [
                "hour" => 0,
                "date" => "2018-05-24T00:00:00+01:00",
                "available" => 1
            ]
        ];
        $schedule2 = [
            [
                "hour" => 1,
                "date" => "2018-05-24T00:00:00+01:00",
                "available" => 1
            ],
            [
                "hour" => 0,
                "date" => "2018-05-25T00:00:00+01:00",
                "available" => 1
            ],
            [
                "hour" => 2,
                "date" => "2018-05-25T00:00:00+01:00",
                "available" => 0
            ]
        ];

        # Create logged in - should work
        assertTrue($u1->login('testpw'));
        $ret = $this->call('schedule', 'POST', [
            'dup' => 1,
            'schedule' => $schedule,
            'userid' => $uid2
        ]);
        assertEquals(0, $ret['ret']);

        $id = $ret['id'];
        assertNotNull($id);

        $ret = $this->call('schedule', 'GET', []);
        $this->log("Returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['schedule']['id']);
        self::assertEquals($schedule, $ret['schedule']['schedule']);

        # Edit
        $ret = $this->call('schedule', 'PATCH', [
            'schedule' => $schedule2,
            'userid' => $uid2
        ]);

        assertEquals(0, $ret['ret']);

        $ret = $this->call('schedule', 'GET', []);
        self::assertEquals($schedule2, $ret['schedule']['schedule']);
        self::assertEquals('Wednesday afternoon, Thursday morning', $ret['schedule']['textversion']);

        # If we get the chatroom between these two users we should find that they have no scheduling matches so far,
        # as we only have a schedule for one user.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $r->createConversation($uid1, $uid2);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $rid);
        list ($msgs, $users) = $r->getMessages();

        foreach ($msgs as $msg) {
            if ($msg['type'] == ChatMessage::TYPE_SCHEDULE_UPDATED || $msg['type'] == ChatMessage::TYPE_SCHEDULE) {
                $this->log("Schedule message " . var_export($msg, TRUE));
                self::assertEquals(0, count($msg['matches']));
            }
        }

        assertTrue($u2->login('testpw'));
        $ret = $this->call('schedule', 'POST', [
            'schedule' => $schedule2,
            'userid' => $uid1
        ]);
        assertEquals(0, $ret['ret']);

        list ($msgs, $users) = $r->getMessages();

        foreach ($msgs as $msg) {
            if ($msg['type'] == ChatMessage::TYPE_SCHEDULE_UPDATED || $msg['type'] == ChatMessage::TYPE_SCHEDULE) {
                $this->log("Schedule message " . var_export($msg, TRUE));
                self::assertEquals(1, count($msg['matches']));
            }
        }
    }

    public function testEmptySummary() {
        $s = new Schedule($this->dbhr, $this->dbhm);
        $summ = $s->getSummary();
        assertEquals(0, strlen($summ));
    }
}

