<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once IZNIK_BASE . '/include/message/Visualise.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class visualiseAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    protected function tearDown()
    {
    }

    public function testBasic()
    {
        # Complete a message.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid1 = $g->create('testgroup1', Group::GROUP_REUSE);

        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm);
        $attid = $a->create(NULL, 'image/jpeg', $data);
        assertNotNull($attid);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
        $msg = str_replace('Test att', 'OFFER: Test (Location)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id1 = $m->save();

        $m1 = new Message($this->dbhr, $this->dbhm, $id1);

        $v = new Visualise($this->dbhr, $this->dbhm);
        $mysqltime = date ("Y-m-d H:i:s");
        $vid = $v->create($id1, $attid, $mysqltime, $m1->getFromuser(), $m1->getFromuser(), 53.1, 1.1, 53.2, 1.2);
        assertNotNull($vid);

        $ret = $this->call('visualise', 'GET', [
            'nelat' => 53.3,
            'nelng' => 1.3,
            'swlat' => 53,
            'swlng' => 1
        ]);
        assertEquals($id1, $ret['list'][0]['msgid']);
    }
}
