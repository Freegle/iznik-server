<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/user/Story.php';


/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class StoryTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->dbhm->preExec("DELETE FROM users_stories WHERE headline =  'Test';");
    }

    protected function tearDown() {
        parent::tearDown ();
        $this->dbhm->preExec("DELETE FROM users_stories WHERE headline =  'Test';");
    }

    public function testCentral() {
        $s = $this->getMockBuilder('Story')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm ])
            ->setMethods(array('sendIt'))
            ->getMock();
        $s->method('sendIt')->willReturn(TRUE);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $sid = $s->create($uid, 1, "Freecycle", "Test");
        $sid = $s->create($uid, 1, "Test", "Test");
        $this->dbhm->preExec("UPDATE users_stories SET reviewed = 1 WHERE id = ?;", [ $sid ]);

        $count = $s->sendToCentral($sid);
        self::assertEquals(1, $count);

        }

    public function testNewsletter() {
        $s = new Story($this->dbhr, $this->dbhm);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $sid = $s->create($uid, 1, "Freecycle", "Test");
        $sid = $s->create($uid, 1, "Test", "Test");
        $s->setAttributes([
            'newsletterreviewed' => 1,
            'newsletter' => 1,
            'reviewed' => 1,
            'public' => 1
        ]);

        $this->dbhm->preExec("UPDATE users_stories SET newsletterreviewed = 1, newsletter = 1 WHERE id = ?;", [ $sid ]);

        $nid = $s->generateNewsletter(1, 10, $sid);
        assertNotNull($nid);
        $this->dbhm->preExec("DELETE FROM newsletters WHERE id = ?;", [ $nid ]);

        }
}

