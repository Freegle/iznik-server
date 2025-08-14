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
class pollAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM polls WHERE name LIKE 'UTTest%';");
    }

    protected function tearDown() : void {
        $this->dbhm->preExec("DELETE FROM polls WHERE name LIKE 'UTTest%';");
        parent::tearDown ();
    }

    public function testBasic() {
        $c = new Polls($this->dbhr, $this->dbhm);
        $id = $c->create('UTTest', 1, 'Test');

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        # Get invalid id
        $ret = $this->call('poll', 'GET', [
            'id' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Get valid id
        $ret = $this->call('poll', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['poll']['id']);

        # Get for user
        $ret = $this->call('poll', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['poll']['id']);

        # Shown
        $ret = $this->call('poll', 'POST', [
            'id' => $id,
            'shown' => true
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Response
        $ret = $this->call('poll', 'POST', [
            'id' => $id,
            'response' => [
                'test' => 'response'
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Get - shouldn't return this one.
        $this->log("Shouldn't return this one");
        $ret = $this->call('poll', 'GET', []);

        $this->assertEquals(0, $ret['ret']);
        if (array_key_exists('poll', $ret)) {
            self::assertNotEquals($id, $ret['poll']['id']);
        }

        }

    public function testLogin() {
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        # Fake FB login.
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_FACEBOOK, NULL, 'testpw'));
        $logins = $u->getLogins();
        $this->log("Got logins " . var_export($logins, TRUE));

        # Create a poll requiring FB.
        $c = new Polls($this->dbhr, $this->dbhm);
        $id = $c->create('UTTest', 1, 'Test', User::LOGIN_FACEBOOK);
        $found = FALSE;

        do {
            # Get for user until we run out or find it.
            $ret = $this->call('poll', 'GET', []);

            $this->assertEquals(0, $ret['ret']);
            $this->assertNotNull($ret['poll']['id']);

            if ($id == $ret['poll']['id']) {
                $found = TRUE;
            }

            # Shown
            $this->log("Mark $id as shown");
            $ret = $this->call('poll', 'POST', [
                'id' => $id,
                'response' => [
                    'test' => true
                ]
            ]);
            $this->assertEquals(0, $ret['ret']);
        } while (!$found);

        $this->assertTrue($found);

        }
}

