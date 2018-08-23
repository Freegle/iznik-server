<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/user/User.php');

use Minishlink\WebPush\WebPush;
use Pheanstalk\Pheanstalk;
use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;

class PushNotifications
{
    const PUSH_GOOGLE = 'Google';
    const PUSH_FIREFOX = 'Firefox';
    const PUSH_TEST = 'Test';
    const PUSH_ANDROID = 'Android';
    const PUSH_IOS = 'IOS';
    const PUSH_FCM_ANDROID = 'FCMAndroid';
    const PUSH_FCM_IOS = 'FCMIOS';
    const APPTYPE_MODTOOLS = 'ModTools';
    const APPTYPE_USER = 'User';

    private $dbhr, $dbhm, $log, $pheanstalk = NULL, $firebase = NULL;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($dbhr, $dbhm);

        if (file_exists('/etc/firebase.json')) {
            $serviceAccount = ServiceAccount::fromJsonFile('/etc/firebase.json');
            $this->firebase = (new Factory)
                ->withServiceAccount($serviceAccount)
                ->create();
            $this->messaging = $this->firebase->getMessaging();
        }
    }

    public function get($userid) {
        # Cache the notification - saves a DB call in GET of session, which is very common.
        $ret = presdef('notification', $_SESSION, NULL);

        if (!$ret) {
            $sql = "SELECT * FROM users_push_notifications WHERE userid = ?;";
            $notifs = $this->dbhr->preQuery($sql, [ $userid ]);
            foreach ($notifs as &$notif) {
                $notif['added'] = ISODate($notif['added']);
                $ret = $notif;
                $_SESSION['notification'] = $ret;
            }
        }

        return($ret);
    }

    public function add($userid, $type, $val) {
        $rc = NULL;

        if ($userid) {
            $apptype = MODTOOLS ? PushNotifications::APPTYPE_MODTOOLS : PushNotifications::APPTYPE_USER;
            $sql = "INSERT INTO users_push_notifications (`userid`, `type`, `subscription`, `apptype`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE userid = ?, type = ?, apptype = ?;";
            $rc = $this->dbhm->preExec($sql, [ $userid, $type, $val, $apptype, $userid, $type, $apptype ]);
            Session::clearSessionCache();
        }

        return($rc);
    }

    public function remove($userid) {
        $sql = "DELETE FROM users_push_notifications WHERE userid = ?;";
        $rc = $this->dbhm->preExec($sql, [ $userid ] );
        return($rc);
    }

    public function uthook($rc = NULL) {
        # Mocked in UT to force an exception.
        return($rc);
    }

    private function queueSend($userid, $type, $params, $endpoint, $payload) {
        #error_log("queueSend $userid $endpoint params " . var_export($params, TRUE));
        try {
            $this->uthook();

            if (!$this->pheanstalk) {
                $this->pheanstalk = new Pheanstalk(PHEANSTALK_SERVER);
            }

            $str = json_encode(array(
                'type' => 'webpush',
                'notiftype' => $type,
                'queued' => time(),
                'userid' => $userid,
                'params' => $params,
                'endpoint' => $endpoint,
                'payload' => $payload
            ));

            $id = $this->pheanstalk->put($str);
        } catch (Exception $e) {
            error_log("Beanstalk exception " . $e->getMessage());
            $this->pheanstalk = NULL;
        }
    }

    public function executeSend($userid, $notiftype, $params, $endpoint, $payload) {
        #error_log("Execute send type $notiftype params " . var_export($params, TRUE) . " payload " . var_export($payload, TRUE) . " endpoint $endpoint");
        try {
            #error_log("notiftype " . $notiftype);
            switch ($notiftype) {
                case PushNotifications::PUSH_FCM_ANDROID:
                case PushNotifications::PUSH_FCM_IOS:
                {
                    # Everything is in one array as passed to this function; split it out into what we need
                    # for FCM.
                    #error_log("FCM notif " . var_export($payload, TRUE));
                    $data = $payload;

                    # We can only have key => string, so the chatids needs to be converted from an array to
                    # a string.
                    $data['chatids'] = implode(',', $data['chatids']);

                    # And anything that isn't a string needs to pretend to be one.  How dull.
                    foreach ($data as $key => $val) {
                        if (gettype($val) !== 'string') {
                            $data[$key] = "$val";
                        }
                    }

                    $data['notId'] = (string)microtime(TRUE);

                    #error_log("Data is " . var_export($data, TRUE));

                    if ($notiftype == PushNotifications::PUSH_FCM_ANDROID) {
                        # Need to omit notification for reasons to do with Cordova plugin.
                        if ($payload['count']) {
                            $data['content-available'] = "1";
                        }

                        $message = CloudMessage::fromArray([
                            'token' => $endpoint,
//                            'notification' => [
//                                'title' => $payload['title'],
//                                'body' => $payload['message']
//                            ],
                            'data' => $data
                        ]);

                        $message = $message->withAndroidConfig([
                            'ttl' => '3600s',
                            'priority' => 'normal'
                        ]);
                    } else {
                        $message = CloudMessage::fromArray([
                            'token' => $endpoint,
                            'notification' => [
                                'title' => $payload['title'],
                                'body' => $payload['message']
                            ],
                            'data' => $data
                        ]);
                        $params = [
                            'headers' => [
                                'apns-priority' => '10',
                            ],
                            'payload' => [
                                'aps' => [
                                    'badge' => $payload['count']
                                ]
                            ],
                        ];

                        if ($payload['title']) {
                            $params['payload']['aps']['alert'] = [
                                'title' => $payload['title'],
                                'body' => $payload['message'],
                            ];
                        }

                        #error_log("Send params " . var_export($params, TRUE));
                        #error_log("Send payload " . var_export($payload, TRUE));
                        $message = $message->withApnsConfig($params);
                    }

                    $ret = $this->messaging->send($message);
                    #error_log("FCM send " . var_export($ret, TRUE));
                    $rc = TRUE;
                    break;
                }
                case PushNotifications::PUSH_GOOGLE:
                case PushNotifications::PUSH_FIREFOX:
                case PushNotifications::PUSH_ANDROID:
                    $params = $params ? $params : [];
                    $webPush = new WebPush($params);
                    ##error_log("Send params " . var_export($params, TRUE));
                    if( ($payload['count'] > 0) && (!is_null($payload['title']))){
                        $rc = $webPush->sendNotification($endpoint, $payload['title'], NULL, TRUE);
                    }
                    else {
                        $rc = TRUE;
                    }
                    break;
                case PushNotifications::PUSH_IOS:
                    try {
                        $deviceToken = $endpoint;
                        $ctx = stream_context_create();
                        $certfile = $payload['modtools'] ? '/etc/modtools_push.pem' : '/etc/user_push.pem';
                        stream_context_set_option($ctx, 'ssl', 'local_cert', $certfile);
                        $fp = stream_socket_client(
                            'ssl://gateway.push.apple.com:2195', $err,
                            $errstr, 60, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $ctx);

                        if ($fp) {
                            $body['aps'] = [
                                'alert' => [
                                    'body' => $payload['title'] . ($payload['message'] ? ": {$payload['message']}" : '')
                                ],
                                'badge' => $payload['badge'],
                                'sound' => 'default',
//                                'content-available' => "1",  Try not sending this as it may be causing notifications to get dropped.
                                'chatids' => $payload['chatids']
                            ];

                            $body['notId'] = microtime();

                            $payload = json_encode($body);
                            $msg = chr(0) . pack('n', 32) . pack('H*', $deviceToken) . pack('n', strlen($payload)) . $payload;
                            stream_set_blocking($fp, 0);
                            $result = fwrite($fp, $msg, strlen($msg));
                            fclose($fp);
                            #error_log("IOS Notification for $userid result " . var_export($result, TRUE));
                        }
                    } catch (Exception $e) { error_log("Exception " . $e->getMessage()); }

                    $rc = TRUE;

                    break;
            }

            #error_log("Returned " . var_export($rc, TRUE) . " for $userid type $notiftype $endpoint payload " . var_export($payload, TRUE));
            $rc = $this->uthook($rc);
        } catch (Exception $e) {
            $rc = [ 'exception' => $e->getMessage() ];
            error_log("Push exception {$rc['exception']}");
        }

        if ($rc !== TRUE) {
            error_log("Push Notification to $userid failed with " . var_export($rc, TRUE));
            $this->dbhm->preExec("DELETE FROM users_push_notifications WHERE userid = ? AND subscription = ?;", [ $userid, $endpoint ]);
        } else {
            # Don't log - lots of these.
            $this->dbhm->preExec("UPDATE users_push_notifications SET lastsent = NOW() WHERE userid = ? AND subscription = ?;", [ $userid, $endpoint  ], FALSE);
        }
    }

    public function notify($userid, $modtools = MODTOOLS) {
        $count = 0;
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $proceed = $u->notifsOn(User::NOTIFS_PUSH);
        #error_log("Notify $userid, on $proceed MT $modtools");

        if ($proceed) {
            $notifs = $this->dbhr->preQuery("SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?;", [
                $userid,
                $modtools ? PushNotifications::APPTYPE_MODTOOLS : PushNotifications::APPTYPE_USER
            ]);

            foreach ($notifs as $notif) {
                #error_log("Send user $userid {$notif['subscription']} type {$notif['type']}");
                $payload = NULL;
                $proceed = TRUE;
                $params = [];

                list ($total, $chatcount, $notifscount, $title, $message, $chatids, $route) = $u->getNotificationPayload($modtools);

                $message = ($total === 0) ? "" : $message;
                if( is_null($message)) $message = "";

                $payload = [
                    'badge' => $total,
                    'count' => $total,
                    'chatcount' => $chatcount,
                    'notifcount' => $notifscount,
                    'title' => $title,
                    'message' => $message,
                    'chatids' => $chatids,
                    'content-available' => $total > 0,
                    'image' => $modtools ? "www/images/modtools_logo.png" : "www/images/user_logo.png",
                    'modtools' => $modtools,
                    'route' => $route
                ];

                switch ($notif['type']) {
                    case PushNotifications::PUSH_GOOGLE:
                    case PushNotifications::PUSH_ANDROID:
                        {
                            $params = [
                                'GCM' => GOOGLE_PUSH_KEY
                            ];
                            break;
                        }
                }

                $this->queueSend($userid, $notif['type'], $params, $notif['subscription'], $payload);
                $count++;
            }
        }

        return($count);
    }

    public function notifyGroupMods($groupid) {
        $ret = TRUE;

        # We background this as it's slow.
        try {
            $this->uthook();

            if (!$this->pheanstalk) {
                $this->pheanstalk = new Pheanstalk(PHEANSTALK_SERVER);
            }

            $str = json_encode(array(
                'type' => 'notifygroupmods',
                'queued' => time(),
                'groupid' => $groupid
            ));

            $id = $this->pheanstalk->put($str);
        } catch (Exception $e) {
            error_log("notifyGroupMods Beanstalk exception " . $e->getMessage());
            $this->pheanstalk = NULL;
            $ret = FALSE;
        }

        return($ret);
    }

    public function executeNotifyGroupGroups($groupid) {
        $count = 0;
        $mods = $this->dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');",
            [ $groupid ]);

        foreach ($mods as $mod) {
            $u = User::get($this->dbhr, $this->dbhm, $mod['userid']);
            $settings = $u->getGroupSettings($groupid);

            if (!array_key_exists('pushnotify', $settings) || $settings['pushnotify']) {
                #error_log("Notify {$mod['userid']} for $groupid notify " . presdef('pushnotify', $settings, TRUE) . " settings " . var_export($settings, TRUE));
                $count += $this->notify($mod['userid'], TRUE);
            }
        }

        return($count);
    }

    public function pokeGroupMods($groupid, $data) {
        $count = 0;
        $mods = $this->dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');",
            [ $groupid ]);

        foreach ($mods as $mod) {
            $this->poke($mod['userid'], $data, TRUE);
            $count++;
        }

        return($count);
    }

    public function fsockopen($host, $port, &$errno, &$errstr) {
        $fp = fsockopen('ssl://' . CHAT_HOST, 443, $errno, $errstr);
        return($fp);
    }

    public function fputs($fp, $str) {
        return(fputs($fp, $str));
    }

    public function poke($userid, $data, $modtools) {
        # This kicks a user who is online at the moment with an outstanding long poll.
        #
        # TODO Handle multiple application servers
        filterResult($data);

        # We want to POST to notify.  We can speed this up using a persistent socket.
        $service_uri = "/publish/$userid";

        $topdata = array(
            'text' => $data,
            'channel' => $userid,
            'modtools' => $modtools,
            'id' => 1
        );

        $vars = json_encode($topdata);

        $header = "Host: " . CHAT_HOST . "\r\n";
        $header .= "User-Agent: Iznik Notify\r\n";
        $header .= "Content-Type: application/json\r\n";
        $header .= "Content-Length: " . strlen($vars) . "\r\n";
        $header .= "Connection: close\r\n\r\n";

        try {
            $fp = $this->fsockopen('ssl://' . CHAT_HOST, 443, $errno, $errstr);

            if (!$fp) {
                error_log("Failed to get socket, $errstr ($errno)");
            } else {
                if (!$this->fputs($fp, "POST $service_uri  HTTP/1.1\r\n")) {
                    # This can happen if the socket is broken.  Just close it ready for next time.
                    fclose($fp);
                    error_log("Failed to post");
                } else {
                    fputs($fp, $header . $vars);
                    $server_response = fread($fp, 512);
                    #error_log("Rsp on $service_uri $server_response");
                }
            }
        } catch (Exception $e) {
            error_log("Failed to notify");
        }
    }
}
