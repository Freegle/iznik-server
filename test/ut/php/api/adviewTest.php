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
class adviewAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

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
        $host= gethostname();
        $ip = gethostbyname($host);
        $_SERVER['REMOTE_ADDR'] = $ip;

        $ret = $this->call('adview', 'GET', [
            'location' => 'Edinburgh'
        ]);

        assertEquals(0, $ret['ret']);

        $ret = $this->call('adview', 'GET', [
            'location' => 'Nowhere'
        ]);
        assertEquals(0, $ret['ret']);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Fake a previous click.
        $ret = $this->call('adview', 'POST', [
            'link' => 'https://adview.online/dispatch/job/publisher/principal-submarine-engineer?pid=2053&psrc=feed&pchl=web&ptkn=037f392bfd21f764cf6a5537ea915f2098354c7b&ptms=eyJpdiI6Ik1hT3R6R0p0ZEl6WGxaMkhyR2t2MVE9PSIsInZhbHVlIjoiSnNnSkw3aEh6MGpZTWo3WER1VytSQT09IiwibWFjIjoiZT'
        ]);

        $u->setSetting('mylocation', [
            'lng' => 55.9533,
            'lat' => 3.1883,
            'name' => 'EH3 6SS'
        ]);

        list ($lat, $lng, $loc) = $u->getLatLng();

        $ret = $this->call('adview', 'GET', [
            'location' => 'EH3 6SS'
        ]);

        assertEquals(0, $ret['ret']);

        $ret = $this->call('adview', 'POST', [
            'link' => 'UT'
        ]);

        assertEquals(0, $ret['ret']);

    }
}
