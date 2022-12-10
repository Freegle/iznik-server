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
class freebieAlertsTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    public function testBasic() {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_FREEGLE);

        # Create member.
        $u = User::get($this->dbhr, $this->dbhm);
        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Submit a message from the member, who will be moderated as new members are.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        $f = new FreebieAlerts($this->dbhr, $this->dbhm);
        $f->add($mid);

        # Check add/remove from spatial kicks background.  We can't check the parameters because some (like 'queued'
        # vary, but should result in two calls.
        $this->waitBackground();
        $this->dbhm->preExec("DELETE FROM messages_spatial WHERE msgid = ?;", [
            $mid
        ]);

        $m = new Message($this->dbhr, $this->dbhm);

        $mock = $this->getMockBuilder('Pheanstalk\Pheanstalk')
            ->disableOriginalConstructor()
            ->setMethods(array('put'))
            ->getMock();

        $mock->expects($this->exactly(2))->method('put');

        $m->setPheanstalk($mock);

        $m->updateSpatialIndex();
        $m->markSuccessfulInSpatial($mid);

        # Manually test calls.  Won't actually call CURL because key is NULL so will return 0.
        $f = new FreebieAlerts($this->dbhr, $this->dbhm);
        $this->assertEquals(0, $f->add($mid));
        $this->assertEquals(0, $f->remove($mid));
    }
}