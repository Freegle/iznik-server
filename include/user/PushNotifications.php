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
use Kreait\Firebase\Exception\Messaging\InvalidMessage;

class PushNotifications
{
    const PUSH_GOOGLE = 'Google';
    const PUSH_FIREFOX = 'Firefox';
    const PUSH_TEST = 'Test';
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

    public function get($userid)
    {
        # Cache the notification - saves a DB call in GET of session, which is very common.
        $ret = presdef('notification', $_SESSION, []);

        if (!$ret) {
            $sql = "SELECT * FROM users_push_notifications WHERE userid = ?;";
            $notifs = $this->dbhr->preQuery($sql, [$userid]);
            foreach ($notifs as &$notif) {
                $notif['added'] = ISODate($notif['added']);
                $ret[] = $notif;
            }

            $_SESSION['notification'] = $ret;
        }

        return ($ret);
    }

    public function add($userid, $type, $val)
    {
        $rc = NULL;

        if ($userid) {
            $apptype = MODTOOLS ? PushNotifications::APPTYPE_MODTOOLS : PushNotifications::APPTYPE_USER;
            $sql = "INSERT INTO users_push_notifications (`userid`, `type`, `subscription`, `apptype`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE userid = ?, type = ?, apptype = ?;";
            $rc = $this->dbhm->preExec($sql, [$userid, $type, $val, $apptype, $userid, $type, $apptype]);
            Session::clearSessionCache();
        }

        return ($rc);
    }

    public function remove($userid)
    {
        $sql = "DELETE FROM users_push_notifications WHERE userid = ?;";
        $rc = $this->dbhm->preExec($sql, [$userid]);
        return ($rc);
    }

    public function uthook($rc = NULL)
    {
        # Mocked in UT to force an exception.
        return ($rc);
    }

    private function queueSend($userid, $type, $params, $endpoint, $payload)
    {
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

    public function executeSend($userid, $notiftype, $params, $endpoint, $payload)
    {
        #error_log("Execute send type $notiftype params " . var_export($params, TRUE) . " payload " . var_export($payload, TRUE) . " endpoint $endpoint");
        try {
            error_log("notiftype " . $notiftype . " userid " . $userid);
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

                        $data['notId'] = (string)floor(microtime(TRUE));

                        #error_log("Data is " . var_export($data, TRUE));

                        if ($notiftype == PushNotifications::PUSH_FCM_ANDROID) {
                            # Need to omit notification for reasons to do with Cordova plugin.
                            if ($payload['count']) {
                                $data['content-available'] = "1";
                            }

                            $message = CloudMessage::fromArray([
                                'token' => $endpoint,
                                'data' => $data
                            ]);

                            $message = $message->withAndroidConfig([
                                'ttl' => '3600s',
                                'priority' => 'normal'
                            ]);
                        } else {
                            $ios = [
                                'token' => $endpoint,
                                'data' => $data
                            ];

                            if (!empty($payload['title'])) {   // Don't set notification if clearing
                                $iostitle = $payload['title'];
                                $iosbody = $payload['message'];

                                if (empty($iosbody)) {  // older iOS only shows body and doesn't show if body empty
                                    $iosbody = $iostitle;
                                    $iostitle = ' ';
                                }

                                $ios['notification'] = [
                                    'title' => $iostitle,
                                    'body' => $iosbody
                                ];
                            }

                            #error_log("ios is " . var_export($ios, TRUE));
                            $message = CloudMessage::fromArray($ios);
                            $params = [
                                'headers' => [
                                    'apns-priority' => '10',
                                ],
                                'payload' => [
                                    'aps' => [
                                        'badge' => $payload['count']
                                        //'content-available' => 1
                                    ]
                                ],
                            ];

                            #error_log("Send params " . var_export($params, TRUE));
                            #error_log("Send payload " . var_export($payload, TRUE));
                            $message = $message->withApnsConfig($params);
                        }

                        try {
                            $this->messaging->validate($message);
                        } catch (InvalidMessage $e) {
                            # We might not want to remove the subscription.  Check the nature of the error
                            # and (for now) record unknown ones to check.
                            $error = $e->errors()['error'];
                            file_put_contents('/tmp/fcmerrors', date(DATE_RFC2822) . ': ' . $userid . ' - ' . $endpoint . ' - ' . var_export($error, TRUE) . "\r\n", FILE_APPEND);
                            error_log("FCM InvalidMessage " . var_export($error, TRUE));
                            $errorCode = 'CODE NOT FOUND';
                            if (array_key_exists('errorCode', $error['details'][0])) {
                                $errorCode = $error['details'][0]['errorCode'];
                            }
                            error_log("FCM errorCode " . $errorCode);

                            if ($errorCode == 'UNREGISTERED') {
                                # We do want to remove the subscription in this case.
                                throw new Exception($errorCode);
                            }

                            foreach ($error['details'] as $detail) {
                                if (array_key_exists('fieldViolations', $detail)) {
                                    if ($detail['fieldViolations'][0]['description'] == 'Invalid registration token') {
                                        # We do want to remove the subscription in this case.
                                        throw new Exception($detail['fieldViolations'][0]['description']);
                                    }
                                }
                            }

                            $rc = TRUE; // Problem is ignored and subscription/token NOT removed: eyeball logs to check
                            break;
                        }

                        $ret = $this->messaging->send($message);
                        error_log("FCM send " . var_export($ret, TRUE));
                        $rc = TRUE;
                        break;
                    }
                case PushNotifications::PUSH_GOOGLE:
                case PushNotifications::PUSH_FIREFOX:
                    $params = $params ? $params : [];
                    $webPush = new WebPush($params);
                    ##error_log("Send params " . var_export($params, TRUE));
                    if (($payload['count'] > 0) && (!is_null($payload['title']))) {
                        $rc = $webPush->sendNotification($endpoint, $payload['title'], NULL, TRUE);
                    } else
                        $rc = TRUE;
                    break;
            }

            #error_log("Returned " . var_export($rc, TRUE) . " for $userid type $notiftype $endpoint payload " . var_export($payload, TRUE));
            $rc = $this->uthook($rc);
        } catch (Exception $e) {
            $rc = ['exception' => $e->getMessage()];
            #error_log("push exc " . var_export($e, TRUE));
            #error_log("push exc " . $e->getMessage());
            error_log("Push exception {$rc['exception']}");
        }

        if ($rc !== TRUE) {
            error_log("Push Notification to $userid failed with " . var_export($rc, TRUE));
            $this->dbhm->preExec("DELETE FROM users_push_notifications WHERE userid = ? AND subscription = ?;", [$userid, $endpoint]);
        } else {
            # Don't log - lots of these.
            $this->dbhm->preExec("UPDATE users_push_notifications SET lastsent = NOW() WHERE userid = ? AND subscription = ?;", [$userid, $endpoint], FALSE);
        }
    }

    public function notify($userid, $modtools = MODTOOLS)
    {
        $count = 0;
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $proceedpush = $u->notifsOn(User::NOTIFS_PUSH);
        $proceedapp = $u->notifsOn(User::NOTIFS_APP);
        #error_log("Notify $userid, push on $proceedpush app on $proceedapp MT $modtools");

        $notifs = $this->dbhr->preQuery("SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?;", [
            $userid,
            $modtools ? PushNotifications::APPTYPE_MODTOOLS : PushNotifications::APPTYPE_USER
        ]);

        foreach ($notifs as $notif) {
            #error_log("Consider notif {$notif['id']} proceed $proceedpush type {$notif['type']}");
            if ($proceedpush && in_array($notif['type'],
                    [PushNotifications::PUSH_FIREFOX, PushNotifications::PUSH_GOOGLE]) ||
                ($proceedapp && in_array($notif['type'],
                        [PushNotifications::PUSH_FCM_ANDROID, PushNotifications::PUSH_FCM_IOS]))) {
                #error_log("Send user $userid {$notif['subscription']} type {$notif['type']}");
                $payload = NULL;
                $proceed = TRUE;
                $params = [];

                # Some Android devices stack the notifications rather than replace them, and the app code doesn't
                # get invoked so can't help.  We can stop this by sending a "clear" notification first.
                $payload = [
                    'badge' => 0,
                    'count' => 0,
                    'chatcount' => 0,
                    'notifcount' => 0,
                    'title' => NULL,
                    'message' => '',
                    'chatids' => [],
                    'content-available' => FALSE,
                    'image' => $modtools ? "www/images/modtools_logo.png" : "www/images/user_logo.png",
                    'modtools' => $modtools,
                    'route' => NULL
                ];

                switch ($notif['type']) {
                    case PushNotifications::PUSH_GOOGLE:
                        {
                            $params = [
                                'GCM' => GOOGLE_PUSH_KEY
                            ];
                            break;
                        }
                }

                $this->queueSend($userid, $notif['type'], $params, $notif['subscription'], $payload);

                # Now on to the real one.
                list ($total, $chatcount, $notifscount, $title, $message, $chatids, $route) = $u->getNotificationPayload($modtools);

                $message = ($total === 0) ? "" : $message;
                if (is_null($message)) $message = "";

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
                        {
                            $params = [
                                'GCM' => GOOGLE_PUSH_KEY
                            ];
                            break;
                        }
                }

                $this->queueSend($userid, $notif['type'], $params, $notif['subscription'], $payload);
                #error_log("Queued send {$notif['type']} for $userid");
                $count++;
            }
        }

        return ($count);
    }

    public function notifyGroupMods($groupid)
    {
        $count = 0;
        $mods = $this->dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');",
            [$groupid]);

        foreach ($mods as $mod) {
            $u = User::get($this->dbhr, $this->dbhm, $mod['userid']);
            $settings = $u->getGroupSettings($groupid);

            if (!array_key_exists('pushnotify', $settings) || $settings['pushnotify']) {
                #error_log("Notify {$mod['userid']} for $groupid notify " . presdef('pushnotify', $settings, TRUE) . " settings " . var_export($settings, TRUE));
                $count += $this->notify($mod['userid'], TRUE);
            }
        }

        return ($count);
    }

    public function pokeGroupMods($groupid, $data)
    {
        $count = 0;
        $mods = $this->dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');",
            [$groupid]);

        foreach ($mods as $mod) {
            $this->poke($mod['userid'], $data, TRUE);
            $count++;
        }

        return ($count);
    }

    public function fsockopen($host, $port, &$errno, &$errstr)
    {
        $fp = @fsockopen($host, $port, $errno, $errstr);
        return ($fp);
    }

    public function fputs($fp, $str)
    {
        return (fputs($fp, $str));
    }

    public function poke($userid, $data, $modtools)
    {
        # We background this as it hits another server, so it may be slow (especially if that server is sick).
        try {
            $this->uthook();

            if (!$this->pheanstalk) {
                $this->pheanstalk = new Pheanstalk(PHEANSTALK_SERVER);
            }

            $str = json_encode(array(
                'type' => 'poke',
                'queued' => time(),
                'groupid' => $userid,
                'data' => $data,
                'modtools' => $modtools
            ));

            $this->pheanstalk->put($str);
            $ret = TRUE;
        } catch (Exception $e) {
            error_log("poke Beanstalk exception " . $e->getMessage());
            $this->pheanstalk = NULL;
            $ret = FALSE;
        }

        return ($ret);
    }

    public function executePoke($userid, $data, $modtools)
    {
        # This kicks a user who is online at the moment with an outstanding long poll.
        filterResult($data);

        # We want to POST to notify.  We can speed this up using a persistent socket.
        $service_uri = "/publish?id=$userid";

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

        if (CHAT_HOST) {
            try {
                #error_log("Connect to " . CHAT_HOST . " port " . CHAT_PORT);
                $fp = $this->fsockopen('ssl://' . CHAT_HOST, CHAT_PORT, $errno, $errstr, 2);

                if ($fp) {
                    if (!$this->fputs($fp, "POST $service_uri  HTTP/1.1\r\n")) {
                        # This can happen if the socket is broken.  Just close it ready for next time.
                        fclose($fp);
                        error_log("Failed to post");
                    } else {
                        fputs($fp, $header . $vars);
                        $server_response = fread($fp, 512);
                        fclose($fp);
                        #error_log("Rsp on $service_uri $server_response");
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to notify");
            }
        }
    }
}
