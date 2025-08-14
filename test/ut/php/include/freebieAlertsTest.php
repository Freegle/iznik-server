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

        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_FREEGLE);

        # Create member.
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        list($member, $memberid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MEMBER, 'Test','User', 'Test User', $email, 'testpw');
        $member->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

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
            'ignoregroupoverride' => TRUE,
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