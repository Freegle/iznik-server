<?php
/**
Class spamc
@author Micah Stevens, September 2008
@version 0.3
== BEGIN LICENSE ==

Licensed under the terms of any of the following licenses at your
choice:

- GNU General Public License Version 2 or later (the "GPL")
http://www.gnu.org/licenses/gpl.html

@copyright GPL
== END LICENSE ==


== USAGE ==

Create instance:
$filter = new spamc();
Configure client:
$filter->host = 'localhost';
$filter->user = 'myspamuser';
$filter->command = 'REPORT'

Filter data - The filter function will return TRUE or FALSE depending on whether the execution was successful. This does not indicate whether
the filter determined if the data was spamc or not.

if (!$filter->filter($data_to_be_filtered)) {
print_r($filter->err);
} else {
print_r($filter->result);
}

Configuration Vars:
host - spamd hostname (default: localhost)
port - spamd port (default: 783)
timeout - network timeout. (default: 30seconds)
user - spamassassin user
command - type of request to make:
CHECK         --  Just check if the passed message is spamc or not and reply as
described below. Filter returns TRUE/FALSE based on server response.

SYMBOLS       --  Check if message is spamc or not, and return score plus list
of symbols hit. Filter returns TRUE/FALSE based on server response.

REPORT        --  Check if message is spamc or not, and return score plus report
Filter returns TRUE/FALSE based on server response.

REPORT_IFSPAM --  Check if message is spamc or not, and return score plus report
if the message is spamc. Filter returns TRUE/FALSE based on server response.

SKIP          --  For compatibility only. Always returns TRUE. No reponse is provided.

PING          --  Return a confirmation that spamd is alive. Filter returns
TRUE/FALSE based on server response. No filtering is done and no report
is provided.

PROCESS       --  Process this message as described above and return modified
message. Filter returns TRUE/FALSE based on server response.



If successful, result of the filter is returned in the 'result' array.

VERSION  	-- Server protocol version
RESPONSE_CODE	-- Response code. 0 is success, >0 is an error.
RESPONSE_STRING -- Response string. EX_OK is success, otherwise error string is provided.
CONTENT_LENGTH 	-- Size of data sent to server. (Only valid if command is PROCESS, otherwise 0)
REPORT		-- Report from server. This format depends on the command issue and may be empty.
SPAM		-- Bool, reports filter decision. (depends on command issued)
SCORE		-- reported spamc score
MAX		-- Max spamc score as configured on the server.

If unsuccessful, the 'err' variable will contain error information.


See http://spamassassin.apache.org/full/3.0.x/dist/spamd/PROTOCOL for details.

 */

class spamc
{
    public $port = 783;
    public $timeout = 30;
    public $host = '127.0.0.1';
    public $user = '';
    public $command = 'PING';
    public $err = '';

    public $result = array('VERSION' => 0,
        'RESPONSE_CODE' => 255,
        'RESPONSE_STRING' => '',
        'CONTENT_LENGTH' => 0,
        'REPORT' => '',
        'SPAM' => FALSE,
        'SCORE' => '',
        'MAX' => '');

    private $connection;
    private $response = array();
    private $errstr, $errno, $out;


    private function _parseHeader($line) {
        preg_match('/^(SPAMD\/)(\d*\.\d*)\s(\d*)\s(.*)/', $line, $matches);
        if ($matches && count($matches) >= 5 && $matches[1] == 'SPAMD/') { // okay, we talked to a spamd server
            $this->result = array('VERSION'=>$matches[2],
                'RESPONSE_CODE'=>$matches[3],
                'RESPONSE_STRING'=>$matches[4]);
            return TRUE;
        } else {
            $this->err = $this->result['RESPONSE_STRING']."(".$this->result['RESPONSE_CODE'].")";
            error_log("Spam check failed " . $this->err);
            return FALSE;
        }

    }
    public function filter($data)
    {

        // how long? skip if just a ping.
        if ($this->command != 'PING') {
            $size = strlen($data);
            $size = $size + 2; // have to add 2 to take care of the /r/n sent to the server.
        }

        // connect to the server.
        $fp = fsockopen($this->host, $this->port, $this->errno, $this->errstr, $this->timeout);
        if (!$fp) {
            //return array('ERROR'=>"$errstr ($errno)");
            $this->err = $this->errstr." (".$this->errno.")";
            return FALSE;
        }

        $this->out = $this->command." SPAMC/1.2\r\n";
        $this->out .= "Content-length: $size\r\n";
        $this->out .= "User: spamfilter\r\n";
        $this->out .= "\r\n";
        $this->out .= $data;
        $this->out .= "\r\n";

        fwrite($fp, $this->out);
        while (!feof($fp)) {
            $this->response[] = fgets($fp, 128);
        }
        fclose($fp);


        // we should have our response, so look at the first line
        $line = array_shift($this->response);

        // process header

        switch ($this->command) {
            case 'CHECK':
                $this->_parseHeader($line);
                #error_log("Result $line " . var_export($this->result, TRUE));
                if (!array_key_exists('RESPONSE_CODE', $this->result) || $this->result['RESPONSE_CODE'] > 0) {
                    // there was an error. Report and return.
                    return FALSE;
                    break;
                }  // no error, continue.

                // check sometimes only returns one line, so parse it and return.
                $line = array_shift($this->response);
                #error_log("CHECK line $line");
                $rc = preg_match("/^(Spam:)\s(True|False)\s;\s(-?\d?\.?\d*)/", $line, $matches);
                if (!$rc) {
                    if (array_key_exists('SPAM', $this->result)) {
                        # CHECK has returned the same kind of result as other calls, so no need
                        # to parse.
                        #error_log("Spam check returned unusual but correct format");
                        break;
                    }

                    #error_log("Spam check returned bad line $line");
                    return FALSE;
                }

                if ($matches[2] == 'True') {
                    $this->result['SPAM'] = TRUE;
                } else {
                    $this->result['SPAM'] = FALSE;
                }

                if (array_key_exists(3, $matches)) {
                    $this->result['SCORE'] = $matches[3];
                }
                $this->result['MAX'] = array_key_exists(4, $matches) ? $matches[4] : 1000;
                break;
            case 'SYMBOLS':
                $this->_parseHeader($line);
                if ($this->result['RESPONSE_CODE'] > 0) {
                    // there was an error. Report and return.
                    return FALSE;
                    break;
                }  // no error, continue.

                $line = array_shift($this->response);
                preg_match('/^(Spam:)\s(True|False)\s;\s(-?\d?\.?\d*)\s\/\s(-?\d?\.?\d*)/', $line, $matches);
                if ($matches[2] == 'True') {
                    $this->result['SPAM'] = TRUE;
                } else {
                    $this->result['SPAM'] = FALSE;
                }
                $this->result['SCORE'] = $matches[3];
                $this->result['MAX'] = $matches[4];
                foreach($this->response as $line) {
                    $this->result['REPORT'] .= $line;
                }
                break;
            case 'REPORT':
                $this->_parseHeader($line);
                if ($this->result['RESPONSE_CODE'] > 0) {
                    // there was an error. Report and return.
                    return FALSE;
                    break;
                }  // no error, continue.

                $line = array_shift($this->response);
                preg_match('/^(Spam:)\s(True|False)\s;\s(-?\d?\.?\d*)\s\/\s(-?\d?\.?\d*)/', $line, $matches);
                if ($matches[2] == 'True') {
                    $this->result['SPAM'] = TRUE;
                } else {
                    $this->result['SPAM'] = FALSE;
                }
                $this->result['SCORE'] = $matches[3];
                $this->result['MAX'] = $matches[4];
                foreach($this->response as $line) {
                    $this->result['REPORT'] .= $line;
                }
                break;
            case "REPORT_IFSPAM":
                $this->_parseHeader($line);
                if ($this->result['RESPONSE_CODE'] > 0) {
                    // there was an error. Report and return.
                    return FALSE;
                    break;
                }  // no error, continue.

                $line = array_shift($this->response);
                preg_match('/^(Spam:)\s(True|False)\s;\s(-?\d?\.?\d*)\s\/\s(-?\d?\.?\d*)/', $line, $matches);
                if ($matches[2] == 'True') {
                    $this->result['SPAM'] = TRUE;
                } else {
                    $this->result['SPAM'] = FALSE;
                }
                $this->result['SCORE'] = $matches[3];
                $this->result['MAX'] = $matches[4];
                if ($this->result['SPAM'] == 'TRUE') {
                    foreach($this->response as $line) {
                        $this->result['REPORT'] .= $line;
                    }
                }
                break;
            case "PROCESS":
                $this->_parseHeader($line);
                if ($this->result['RESPONSE_CODE'] > 0) {
                    // there was an error. Report and return.
                    return FALSE;
                    break;
                }  // no error, continue.

                $line = array_shift($this->response);
                preg_match('/^(Content-length:)\s(\d*)/', $line, $matches);
                $this->result['CONTENT_LENGTH'] = $matches[2];
                foreach($this->response as $line) {
                    $this->result['REPORT'] .= $line;
                }
                break;
            case "SKIP":
                return TRUE;
                break;
            case "PING":
                return $this->_parseHeader($line);
                break;
            default:
                $this->err = "INVALID COMMAND\n";
                return FALSE;
                break;
        }

        return TRUE;
    }
}
