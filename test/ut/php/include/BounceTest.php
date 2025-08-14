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
class BounceTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM bounces WHERE `to` = 'bounce-test@" . USER_DOMAIN . "';");
    }

    public function testBasic()
    {
        list($u, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/bounce');
        $b = new Bounce($this->dbhr, $this->dbhm);
        $id = $b->save("bounce-{$this->uid}-1234@" . USER_DOMAIN, $msg);
        $this->assertNotNull($id);
        $this->assertTrue($b->process($id));

        $this->waitBackground();
        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $log = $this->findLog(Log::TYPE_USER, Log::SUBTYPE_BOUNCE, $logs[$u->getId()]['logs']);
        $this->assertEquals($this->uid, $log['user']['id']);

        $b->suspendMail($this->uid, 0, 0);
        $this->waitBackground();
        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $log = $this->findLog(Log::TYPE_USER, Log::SUBTYPE_SUSPEND_MAIL, $logs[$u->getId()]['logs']);
        $this->assertEquals($this->uid, $log['user']['id']);

        }
}
