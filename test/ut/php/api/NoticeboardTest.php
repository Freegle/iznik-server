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
class noticeboardAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    private $msgsSent = [];

    public function sendMock($mailer, $message)
    {
        $this->msgsSent[] = $message->getSubject();
    }

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM noticeboards WHERE name LIKE 'UTTest%';");
    }

    protected function tearDown() : void {
        $this->dbhm->preExec("DELETE FROM noticeboards WHERE name LIKE 'UTTest%';");
        parent::tearDown ();
    }

    public function testBasic() {
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        # Invalid parameters
        $ret = $this->call('noticeboard', 'POST', [ 'dup' => 1]);
        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('noticeboard', 'GET', [
            'id' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Valid create
        $ret = $this->call('noticeboard', 'POST', [
            'lat' => 8.53333,
            'lng' => 179.2167,
            'description' => 'Test description'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);

        $ret = $this->call('noticeboard', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['noticeboard']['id']);
        $this->assertEquals('Test description', $ret['noticeboard']['description']);
        $this->assertEquals(8.5333, $ret['noticeboard']['lat']);
        $this->assertEquals(179.2167, $ret['noticeboard']['lng']);
        $this->assertEquals($this->uid, $ret['noticeboard']['addedby']['id']);

        $ret = $this->call('noticeboard', 'PATCH', [
            'id' => $id,
            'name' => 'UTTest2',
            'lat' => 9.53333,
            'lng' => 180.2167,
            'description' => 'Test description2'
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Add a photo
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_NOTICEBOARD);
        list ($photoid, $uid) = $a->create(NULL, $data);

        $ret = $this->call('noticeboard', 'PATCH', [
            'id' => $id,
            'photoid' => $photoid,
            'action' => 'SetPhoto'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('noticeboard', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['noticeboard']['id']);
        $this->assertEquals('UTTest2', $ret['noticeboard']['name']);
        $this->assertEquals('Test description2', $ret['noticeboard']['description']);
        $this->assertEquals(9.5333, $ret['noticeboard']['lat']);
        $this->assertEquals(180.2167, $ret['noticeboard']['lng']);
        $this->assertEquals(0, count($ret['noticeboard']['checks']));

        $n = $this->getMockBuilder('Freegle\Iznik\Noticeboard')
            ->setConstructorArgs(array($this->dbhm, $this->dbhm))
            ->setMethods(array('sendIt'))
            ->getMock();

        $n->method('sendIt')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        $n->thank($this->uid, $id);
        $this->assertEquals(1, count($this->msgsSent));
        $this->assertEquals('Thanks for putting up a poster!', $this->msgsSent[0]);

        # Now updates.
        $ret = $this->call('noticeboard', 'POST', [
            'id' => $id,
            'action' => Noticeboard::ACTION_REFRESHED
        ]);
        $ret = $this->call('noticeboard', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['noticeboard']['checks']));
        $this->assertEquals(1, $ret['noticeboard']['checks'][0]['refreshed']);

        $ret = $this->call('noticeboard', 'POST', [
            'id' => $id,
            'action' => Noticeboard::ACTION_COMMENTS,
            'comments' => 'Test'
        ]);
        $ret = $this->call('noticeboard', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(2, count($ret['noticeboard']['checks']));
        $this->assertEquals('Test', $ret['noticeboard']['checks'][0]['comments']);

        $ret = $this->call('noticeboard', 'POST', [
            'id' => $id,
            'action' => Noticeboard::ACTION_DECLINED
        ]);
        $ret = $this->call('noticeboard', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(3, count($ret['noticeboard']['checks']));
        $this->assertEquals(1, $ret['noticeboard']['checks'][0]['declined']);

        $ret = $this->call('noticeboard', 'POST', [
            'id' => $id,
            'action' => Noticeboard::ACTION_INACTIVE
        ]);
        $ret = $this->call('noticeboard', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(4, count($ret['noticeboard']['checks']));
        $this->assertEquals(1, $ret['noticeboard']['checks'][0]['inactive']);
    }

    public function testAuthority() {
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        # Valid create
        $ret = $this->call('noticeboard', 'POST', [
            'lat' => 8.53333,
            'lng' => 179.2167,
            'description' => 'Test description'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        $a = new Authority($this->dbhr, $this->dbhm);
        $aid = $a->create("UTAuth", 'GLA', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');


        $ret = $this->call('noticeboard', 'GET', [
            'authorityid' => $aid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['noticeboards']));
        $this->assertEquals($id, $ret['noticeboards'][0]['id']);
    }
}

