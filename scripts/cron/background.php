<?php
#
#  This script handles less critical background tasks.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Pheanstalk\Pheanstalk;

$opts = getopt('n:');
$instancename = Utils::presdef('n', $opts, '');
$fn = basename(__FILE__ . '_' . $instancename);
$lockh = Utils::lockScript($fn);

$dbhm->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, TRUE);

function doSQL($sql) {
    global $dbhm;

    try {
        $rc = $dbhm->exec($sql, FALSE);
    } catch (\Exception $e) {
        $msg = $e->getMessage();

        if (strpos($e, 'gone away')) {
            # SQL server has gone away.  Exit - cron will restart and we'll get new handles.
            error_log("SQL gone away - exit");
            exit(1);
        }

        error_log("SQL exception " . var_export($e, TRUE));
    }
}

try {
    $pheanstalk = new Pheanstalk('127.0.0.1');
    $exit = FALSE;

    while (!$exit) {
        $job = $pheanstalk->reserve();

         $count = 0;
         $chatlistsqueued = 0;

        try {
            $count++;
            $data = json_decode($job->getData(), true);

            if ($data) {
                switch ($data['type']) {
                    case 'sql': {
                        doSQL($data['sql']);
                        break;
                    }

                    case 'sqlfile': {
                        $sql = file_get_contents($data['file']);
                        unlink($data['file']);
                        doSQL($sql);
                        break;
                    }

                    case 'webpush': {
                        $n = new PushNotifications($dbhr, $dbhm);

                        # Some Android devices stack the notifications rather than replace them, and the app code doesn't
                        # get invoked so can't help.  We can stop this by sending a "clear" notification first.  We do
                        # this here rather than queueing two of them because there are multiple instances and we can
                        # end up with them out of order.
                        $payload = [
                            'badge' => 0,
                            'count' => 0,
                            'chatcount' => 0,
                            'notifcount' => 0,
                            'title' => NULL,
                            'message' => '',
                            'chatids' => [],
                            'content-available' => FALSE,
                            'image' => $data['payload']['image'],
                            'modtools' => $data['payload']['modtools'],
                            'route' => NULL
                        ];

                        switch ($data['notiftype']) {
                            case PushNotifications::PUSH_GOOGLE:
                            {
                                $params = [
                                    'GCM' => GOOGLE_PUSH_KEY
                                ];
                                break;
                            }
                        }

                        $n->executeSend($data['userid'], $data['notiftype'], $data['params'], $data['endpoint'], $payload);

                        # Now the real one.
                        $n->executeSend($data['userid'], $data['notiftype'], $data['params'], $data['endpoint'], $data['payload']);
                        break;
                    }

                    case 'poke': {
                        $n = new PushNotifications($dbhr, $dbhm);
                        $n->executePoke($data['groupid'], $data['data'], $data['modtools']);
                        break;
                    }

                    case 'facebooknotif': {
                        $n = new Facebook($dbhr, $dbhm);
                        $n->executeNotify($data['fbid'], $data['message'], $data['href']);
                        break;
                    }

                    case 'exit': {
                        error_log("Asked to exit");
                        $exit = TRUE;
                        break;
                    }

                    default: {
                        error_log("Unknown job type {$data['type']} " . var_export($data, TRUE));
                    }
                }
            }
        } catch (\Exception $e) { error_log("Exception " . $e->getMessage()); }

        # Whatever it is, we need to delete the job to avoid getting stuck.
        $rc = $pheanstalk->delete($job);
    }
} catch (\Exception $e) {
    error_log("Top-level exception " . $e->getMessage() . "\n");
}

Utils::unlockScript($lockh);