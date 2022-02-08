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

        if (strpos($e, 'gone away') || strpos($e, 'Lock wait timeout exceeded')) {
            # SQL server has gone away.  Exit - cron will restart and we'll get new handles.
            error_log("SQL gone away - exit");
            exit(1);
        }

        error_log("SQL exception " . var_export($e, TRUE));
    }
}

try {
    $exit = FALSE;

    while (!$exit) {
        $job = NULL;

        try {
            // Pheanstalk doesn't recovery well after an error, so recreate each time.
            error_reporting(0);
            $pheanstalk = Pheanstalk::create('127.0.0.1');
            $job = $pheanstalk->reserve();
            error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
        } catch (\Exception $e) {
            error_log("Failed to reserve, sleeping");
            sleep(1);
        }

        if ($job) {
            try {
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

                            try {
                                $n->executeSend($data['userid'], $data['notiftype'], $data['params'], $data['endpoint'], $payload);
                            } catch (\Exception $e) {}

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

                        case 'freebiealertsadd': {
                            $f = new FreebieAlerts($dbhr, $dbhm);
                            error_log("Background thread add {$data['msgid']}");
                            $f->add($data['msgid']);
                            break;
                        }

                        case 'freebiealertsremove': {
                            $f = new FreebieAlerts($dbhr, $dbhm);
                            error_log("Background thread remove {$data['msgid']}");
                            $f->remove($data['msgid']);
                            break;
                        }

                        default: {
                            error_log("Unknown job type {$data['type']} " . var_export($data, TRUE));
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("Exception " . $e->getMessage());
                if ($job) {
                    \Sentry\captureException($e);
                }
            }

            # Whatever it is, we need to delete the job to avoid getting stuck.
            $pheanstalk->delete($job);

            if (file_exists('/tmp/iznik.background.abort')) {
                $exit = TRUE;
            }
        }
    }
} catch (\Exception $e) {
    error_log("Top-level exception " . $e->getMessage() . "\n");
    \Sentry\captureException($e);
}

Utils::unlockScript($lockh);