<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');
require_once(UT_DIR . '/../../include/misc/IPBlocker.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class IPBlockerTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp(): void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        # Clean up any existing test blocks and whitelist
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData()
    {
        # Clean up block files for test IPs
        $testIPs = ['192.168.99.1', '192.168.99.2', '192.168.99.3', '192.168.99.100'];
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);

        foreach ($testIPs as $ip) {
            $ipBlocker->unblockIP($ip);
            $ipBlocker->removeFromWhitelist($ip);
        }
    }

    public function testBlockIP()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '192.168.99.1';

        # Block the IP
        $result = $ipBlocker->blockIP($testIP, 'Test reason', 123, 'Test User', 'test@test.com');
        $this->assertTrue($result);

        # Verify it's blocked
        $this->assertTrue($ipBlocker->isBlocked($testIP));

        # Get block info
        $blockInfo = $ipBlocker->getBlockInfo($testIP);
        $this->assertNotNull($blockInfo);
        $this->assertEquals($testIP, $blockInfo['ip']);
        $this->assertEquals('Test reason', $blockInfo['reason']);
        $this->assertEquals(1, $blockInfo['block_count']);
        $this->assertEquals(IPBlocker::INITIAL_BLOCK_DURATION, $blockInfo['duration']);
        $this->assertEquals(123, $blockInfo['userid']);
        $this->assertEquals('Test User', $blockInfo['username']);
        $this->assertEquals('test@test.com', $blockInfo['email']);
    }

    public function testUnblockIP()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '192.168.99.2';

        # Block then unblock
        $ipBlocker->blockIP($testIP, 'Test reason');
        $this->assertTrue($ipBlocker->isBlocked($testIP));

        $result = $ipBlocker->unblockIP($testIP);
        $this->assertTrue($result);
        $this->assertFalse($ipBlocker->isBlocked($testIP));

        # Unblocking a non-blocked IP should return FALSE
        $result = $ipBlocker->unblockIP($testIP);
        $this->assertFalse($result);
    }

    public function testWhitelist()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '192.168.99.3';

        # Add to whitelist
        $result = $ipBlocker->addToWhitelist($testIP);
        $this->assertTrue($result);

        # Verify it's whitelisted
        $this->assertTrue($ipBlocker->isWhitelisted($testIP));

        # Try to block a whitelisted IP
        $result = $ipBlocker->blockIP($testIP, 'Test reason');
        $this->assertFalse($result);
        $this->assertFalse($ipBlocker->isBlocked($testIP));

        # Remove from whitelist
        $result = $ipBlocker->removeFromWhitelist($testIP);
        $this->assertTrue($result);
        $this->assertFalse($ipBlocker->isWhitelisted($testIP));
    }

    public function testExponentialBackoff()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '192.168.99.100';

        # First block: 1 hour
        $ipBlocker->blockIP($testIP, 'First block');
        $blockInfo = $ipBlocker->getBlockInfo($testIP);
        $this->assertEquals(1, $blockInfo['block_count']);
        $this->assertEquals(3600, $blockInfo['duration']); // 1 hour

        # Second block: 2 hours
        $ipBlocker->blockIP($testIP, 'Second block');
        $blockInfo = $ipBlocker->getBlockInfo($testIP);
        $this->assertEquals(2, $blockInfo['block_count']);
        $this->assertEquals(7200, $blockInfo['duration']); // 2 hours

        # Third block: 4 hours
        $ipBlocker->blockIP($testIP, 'Third block');
        $blockInfo = $ipBlocker->getBlockInfo($testIP);
        $this->assertEquals(3, $blockInfo['block_count']);
        $this->assertEquals(14400, $blockInfo['duration']); // 4 hours

        # Fourth block: 8 hours
        $ipBlocker->blockIP($testIP, 'Fourth block');
        $blockInfo = $ipBlocker->getBlockInfo($testIP);
        $this->assertEquals(4, $blockInfo['block_count']);
        $this->assertEquals(28800, $blockInfo['duration']); // 8 hours
    }

    public function testMaxBlockDuration()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '192.168.99.1';

        # Block multiple times to reach max duration
        for ($i = 0; $i < 20; $i++) {
            $ipBlocker->blockIP($testIP, "Block #$i");
        }

        $blockInfo = $ipBlocker->getBlockInfo($testIP);
        $this->assertEquals(20, $blockInfo['block_count']);
        $this->assertEquals(IPBlocker::MAX_BLOCK_DURATION, $blockInfo['duration']);
        $this->assertEquals(604800, $blockInfo['duration']); // 7 days
    }

    public function testBlockInfoForNonBlockedIP()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '192.168.99.99';

        $blockInfo = $ipBlocker->getBlockInfo($testIP);
        $this->assertNull($blockInfo);
    }

    public function testCleanupExpired()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '192.168.99.1';

        # Create a block that's already expired by manipulating the file
        $ipBlocker->blockIP($testIP, 'Test expired block');
        $blockFile = IPBlocker::BLOCK_DIR . '/192_168_99_1.json';

        # Manually set the block to be expired
        $data = json_decode(file_get_contents($blockFile), TRUE);
        $data['blocked_until'] = time() - 100; // Expired 100 seconds ago
        file_put_contents($blockFile, json_encode($data));

        # Verify the file exists before cleanup
        $this->assertTrue(file_exists($blockFile));

        # Clean up expired blocks
        $cleaned = $ipBlocker->cleanupExpired();
        $this->assertGreaterThanOrEqual(1, $cleaned);

        # Verify the file is gone
        $this->assertFalse(file_exists($blockFile));
    }

    public function testIPv6Addresses()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        # Block IPv6 address
        $result = $ipBlocker->blockIP($testIP, 'IPv6 test');
        $this->assertTrue($result);

        # Verify it's blocked
        $this->assertTrue($ipBlocker->isBlocked($testIP));

        # Verify block info
        $blockInfo = $ipBlocker->getBlockInfo($testIP);
        $this->assertNotNull($blockInfo);
        $this->assertEquals($testIP, $blockInfo['ip']);
    }

    public function testAddSameIPToWhitelistTwice()
    {
        $ipBlocker = new IPBlocker($this->dbhr, $this->dbhm);
        $testIP = '192.168.99.1';

        # Add to whitelist first time
        $result = $ipBlocker->addToWhitelist($testIP);
        $this->assertTrue($result);

        # Add to whitelist second time (should return FALSE as already exists)
        $result = $ipBlocker->addToWhitelist($testIP);
        $this->assertFalse($result);
    }
}
