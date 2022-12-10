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
class apiTest extends IznikAPITestCase {
    public function testBadCall() {
        $ret = $this->call('unknown', 'GET', []);
        $this->assertEquals(1000, $ret['ret']);

        }

    public function testDuplicatePOST() {
        # We prevent duplicate posts within a short time.
        $this->log("POST - should work");
        $ret = $this->call('test', 'POST', []);
        $this->assertEquals(1000, $ret['ret']);

        $this->log("POST - should fail");
        $ret = $this->call('test', 'POST', []);
        $this->assertEquals(999, $ret['ret']);

        sleep(DUPLICATE_POST_PROTECTION + 1);
        $this->log("POST - should work");
        $ret = $this->call('test', 'POST', []);
        $this->assertEquals(1000, $ret['ret']);

        }

    public function testException() {
        $ret = $this->call('exception', 'POST', []);
        $this->assertEquals(998, $ret['ret']);

        # Should fail a couple of times and then work.
        $ret = $this->call('DBexceptionWork', 'POST', []);
        $this->assertEquals(1000, $ret['ret']);

        # Should fail.
        $ret = $this->call('DBexceptionFail', 'POST', []);
        $this->assertEquals(997, $ret['ret']);

        }

    public function testLeaveTrans() {
        # Should fail a couple of times and then work.
        $ret = $this->call('DBleaveTrans', 'POST', []);
        $this->assertEquals(1000, $ret['ret']);

        }

    public function testOptions() {
        # Testing header output is hard
        # TODO ...but doable, apparently.
        $ret = $this->call('test', 'OPTIONS', []);
        $this->assertTrue(TRUE);

        }

    public function testOverride() {
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'get';
        $ret = $this->call('echo', 'GET', []);
        unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        $this->assertEquals('get', $ret['type']);

        }

    public function testModel() {
        $ret = $this->call('wibble', 'GET', [
            'model' => json_encode([
                'call' => 'echo'
            ])
        ]);

        $this->log(var_export($ret, true));

        $this->assertEquals('echo', $ret['call']);

        }
}

