<?php

namespace {

    use PhpMimeMailParser\Parser;
    use PhpMimeMailParser\Attachment;
    use PhpMimeMailParser\Exception;

    // This allow us to configure the behavior of the "global mock"
    $mockTmpFile = FALSE;
    $mockFopen = FALSE;
}

namespace PhpMimeMailParser {

    function tmpfile()
    {
        global $mockTmpFile;
        if (isset($mockTmpFile) && $mockTmpFile ==  TRUE) {
            return FALSE;
        } else {
            return call_user_func_array('\tmpfile', func_get_args());
        }
    }

    function fopen()
    {
        global $mockFopen;
        if (isset($mockFopen) && $mockFopen ==  TRUE) {
            return FALSE;
        } else {
            return call_user_func_array('\fopen', func_get_args());
        }
    }

    /**
     * ExceptionTest of php-mime-mail-parser
     *
     * Fully Tested Mailparse Extension Wrapper for PHP 5.4+
     *
     */

    class ExceptionTest extends \PHPUnit_Framework_TestCase
    {
        public function setUp()
        {
            global $mockTmpFile;
            $mockTmpFile = FALSE;

            global $mockFopen;
            $mockFopen = FALSE;
        }

        /**
         * @expectedException        Exception
         * @expectedExceptionMessage setPath() or setText() or setStream() must be called before
         */
        public function testGetHeader()
        {
            $Parser = new Parser();
            $Parser->getHeader('test');
        }

        /**
         * @expectedException        Exception
         * @expectedExceptionMessage setPath() or setText() or setStream() must be called before
         */
        public function testGetHeaders()
        {
            $Parser = new Parser();
            $Parser->getHeaders();
        }

        /**
         * @expectedException        Exception
         * @expectedExceptionMessage Invalid type specified for getMessageBody(). "type" can either be text or html.
         */
        public function testgetMessageBody()
        {
            $Parser = new Parser();
            $Parser->getMessageBody('azerty');
        }

        /**
         * @expectedException        Exception
         * @expectedExceptionMessage setStream() expects parameter stream to be readable stream resource.
         */
        public function testSetStream()
        {
            $Parser = new Parser();
            $Parser->setStream('azerty');
        }

        /**
         * @expectedException        Exception
         * @expectedExceptionMessage Could not create temporary files for attachments.
         */
        public function testSetStreamWithoutTmpPermissions()
        {
            global $mockTmpFile;
            $mockTmpFile = TRUE;

            $file = __DIR__.'/mails/m0001';

            $Parser = new Parser();
            $Parser->setStream(fopen($file, 'r'));
        }

        /**
         * @expectedException        Exception
         * @expectedExceptionMessage Could not create temporary files for attachments.
         */
        public function testGetAttachmentStreamWithoutTmpPermissions()
        {

            $file = __DIR__.'/mails/m0001';

            $Parser = new Parser();
            $Parser->setStream(fopen($file, 'r'));

            global $mockTmpFile;
            $mockTmpFile = TRUE;

            $attachments = $Parser->getAttachments();
        }

        /**
         * @expectedException        Exception
         * @expectedExceptionMessage setStream() expects parameter stream to be readable stream resource.
         */
        public function testSetStreamResource()
        {
            $c = socket_create(AF_UNIX, SOCK_STREAM, 0);
            $Parser = new Parser();
            $Parser->setStream($c);
        }

        /**
         * @expectedException        Exception
         * @expectedExceptionMessage Could not write attachments. Your directory may be unwritable by PHP.
         */
        public function testSaveAttachmentsWithoutPermissions()
        {
            $mid = 'm0001';
            $file = __DIR__.'/mails/'.$mid;
            $attach_dir = __DIR__.'/mails/attach_'.$mid.'/';
            $attach_url = 'http://www.company.com/attachments/'.$mid.'/';

            $Parser = new Parser();
            $Parser->setStream(fopen($file, 'r'));

            global $mockFopen;
            $mockFopen = TRUE;

            $Parser->saveAttachments($attach_dir, $attach_url);
        }

        /**
         * @expectedException        PHPUnit_Framework_Error
         */
        public function testWrongCharset()
        {
            $mid = 'm0017';
            $file = __DIR__.'/mails/'.$mid;

            $Parser = new Parser();
            $Parser->setStream(fopen($file, 'r'));

            $text = $Parser->getMessageBody('text');
        }
    }
}
