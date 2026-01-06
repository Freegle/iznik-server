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
class TusTest extends IznikTestCase {

    public function testGetHeadersBasic() {
        $rawHeaders = "HTTP/1.1 201 Created\r\nLocation: http://example.com/files/123\r\nContent-Type: application/json\r\n\r\n";

        $headers = Tus::getHeaders($rawHeaders);

        $this->assertEquals('HTTP/1.1 201 Created', $headers['http_code']);
        $this->assertEquals('http://example.com/files/123', $headers['location']);
        $this->assertEquals('application/json', $headers['content-type']);
    }

    public function testGetHeadersWithTusHeaders() {
        $rawHeaders = "HTTP/1.1 200 OK\r\nTus-Resumable: 1.0.0\r\nUpload-Offset: 0\r\nUpload-Length: 12345\r\n\r\n";

        $headers = Tus::getHeaders($rawHeaders);

        $this->assertEquals('HTTP/1.1 200 OK', $headers['http_code']);
        $this->assertEquals('1.0.0', $headers['tus-resumable']);
        $this->assertEquals('0', $headers['upload-offset']);
        $this->assertEquals('12345', $headers['upload-length']);
    }

    public function testGetHeadersLowerCasesKeys() {
        $rawHeaders = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Custom-Header: test\r\n\r\n";

        $headers = Tus::getHeaders($rawHeaders);

        $this->assertArrayHasKey('content-type', $headers);
        $this->assertArrayHasKey('x-custom-header', $headers);
    }

    public function testGetHeadersWithMultipleHeaders() {
        $rawHeaders = "HTTP/1.1 201 Created\r\n" .
            "Location: http://tus.example.com/files/abc\r\n" .
            "Tus-Resumable: 1.0.0\r\n" .
            "Tus-Version: 1.0.0\r\n" .
            "Tus-Extension: creation,expiration\r\n" .
            "Upload-Expires: Wed, 01 Jan 2025 00:00:00 GMT\r\n" .
            "\r\n" .
            "Body content here";

        $headers = Tus::getHeaders($rawHeaders);

        $this->assertEquals('http://tus.example.com/files/abc', $headers['location']);
        $this->assertEquals('1.0.0', $headers['tus-resumable']);
        $this->assertEquals('1.0.0', $headers['tus-version']);
        $this->assertEquals('creation,expiration', $headers['tus-extension']);
        $this->assertEquals('Wed, 01 Jan 2025 00:00:00 GMT', $headers['upload-expires']);
    }

    public function testGetHeadersEmptyBody() {
        $rawHeaders = "HTTP/1.1 204 No Content\r\nCache-Control: no-cache\r\n\r\n";

        $headers = Tus::getHeaders($rawHeaders);

        $this->assertEquals('HTTP/1.1 204 No Content', $headers['http_code']);
        $this->assertEquals('no-cache', $headers['cache-control']);
    }

    public function testGetHeaders404Response() {
        $rawHeaders = "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain\r\n\r\n";

        $headers = Tus::getHeaders($rawHeaders);

        $this->assertEquals('HTTP/1.1 404 Not Found', $headers['http_code']);
    }

    public function testGetHeadersWithColonInValue() {
        // Values containing colons should work correctly.
        $rawHeaders = "HTTP/1.1 200 OK\r\nDate: Wed, 01 Jan 2025 12:00:00 GMT\r\n\r\n";

        $headers = Tus::getHeaders($rawHeaders);

        // Note: current implementation splits on first ': ' so this should work.
        $this->assertStringContainsString('2025', $headers['date']);
    }
}
