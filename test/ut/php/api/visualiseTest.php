<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

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

        # Create another person who replied.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->setSetting('mylocation', [
            'lng' => 179.15,
            'lat' => 8.5
        ]);
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $r->createConversation($uid, $m1->getFromuser());
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $cmid = $cm->create($rid, $uid, "Hello?", ChatMessage::TYPE_INTERESTED, $id1);
        assertNotNull($cmid);
        error_log("Created reply to $id1 from $uid");

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
        assertEquals(1, count($ret['list'][0]['others']));
    }
}
