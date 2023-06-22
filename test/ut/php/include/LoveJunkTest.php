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
class LoveJunkTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM returnpath_seedlist WHERE email LIKE 'test@test.com';");
    }

    public function testSend()
    {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(null, null, 'Test User');

        $settings = [
            'mylocation' => [
                'lat' => 55.957571,
                'lng' => -3.205333,
                'name' => 'EH3 6SS'
            ],
        ];

        $u->setPrivate('settings', json_encode($settings));

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_REUSE);

        $email = 'test-' . rand() . '@blackhole.io';
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, null, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $u->addEmail('test@test.com');
        $u->addMembership($group1);
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: sofa (EH3 6SS)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $msg = str_replace('Hey.', 'Testing', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 55.957571);
        $m->setPrivate('lng', -3.205333);

        $l = new LoveJunk($this->dbhr, $this->dbhm);
        $l->setMock(true);
        $this->assertTrue($l->send($id));
    }
}