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
class stripeCreateIntentAPITest extends IznikAPITestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    /**
     * Test that amounts below £1 are rejected
     * @dataProvider invalidAmountProvider
     */
    public function testMinimumAmountValidation($amount, $expectedRet)
    {
        // Create and login a test user
        list($u, $id, $emailid) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test@test.com', 'testpw');

        $ret = $this->call('stripecreateintent', 'POST', [
            'amount' => $amount,
            'test' => TRUE
        ]);

        $this->assertEquals($expectedRet, $ret['ret']);

        if ($expectedRet == 2) {
            $this->assertStringContainsString('at least £1', $ret['status']);
        }
    }

    public function invalidAmountProvider()
    {
        return [
            'zero amount' => [0, 2],
            'negative amount' => [-1, 2],
            // Note: 0.50 is converted to 0 by Utils::presint, so it's rejected as zero
            'amount below minimum (float)' => [0.50, 2],
        ];
    }
}
