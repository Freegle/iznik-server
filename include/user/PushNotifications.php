<?php
namespace Freegle\Iznik;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Pheanstalk\Pheanstalk;
use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;

class PushNotifications
{
    const PUSH_GOOGLE = 'Google'; // Obsolete
    const PUSH_FIREFOX = 'Firefox'; // Obsolete
    const PUSH_TEST = 'Test';
    const PUSH_FCM_ANDROID = 'FCMAndroid';
    const PUSH_FCM_IOS = 'FCMIOS';
    const APPTYPE_MODTOOLS = 'ModTools';
    const APPTYPE_USER = 'User';
    const PUSH_BROWSER_PUSH = 'BrowserPush';

    // Notification categories - these map to Android channels and iOS categories
    const CATEGORY_CHAT_MESSAGE = 'CHAT_MESSAGE';
    const CATEGORY_CHITCHAT_COMMENT = 'CHITCHAT_COMMENT';
    const CATEGORY_CHITCHAT_REPLY = 'CHITCHAT_REPLY';
    const CATEGORY_CHITCHAT_LOVED = 'CHITCHAT_LOVED';
    const CATEGORY_POST_REMINDER = 'POST_REMINDER';
    const CATEGORY_NEW_POSTS = 'NEW_POSTS';
    const CATEGORY_COLLECTION = 'COLLECTION';
    const CATEGORY_EVENT_SUMMARY = 'EVENT_SUMMARY';
    const CATEGORY_EXHORT = 'EXHORT';

    // Category configuration: iOS interruption level, Android channel ID, Android priority
    const CATEGORIES = [
        self::CATEGORY_CHAT_MESSAGE => [
            'ios_interruption' => 'time-sensitive',
            'android_channel' => 'chat_messages',
            'android_priority' => 'high'
        ],
        self::CATEGORY_CHITCHAT_COMMENT => [
            'ios_interruption' => 'passive',
            'android_channel' => 'social',
            'android_priority' => 'normal'
        ],
        self::CATEGORY_CHITCHAT_REPLY => [
            'ios_interruption' => 'passive',
            'android_channel' => 'social',
            'android_priority' => 'normal'
        ],
        self::CATEGORY_CHITCHAT_LOVED => [
            'ios_interruption' => 'passive',
            'android_channel' => 'social',
            'android_priority' => 'normal'
        ],
        self::CATEGORY_POST_REMINDER => [
            'ios_interruption' => 'active',
            'android_channel' => 'reminders',
            'android_priority' => 'normal'
        ],
        self::CATEGORY_NEW_POSTS => [
            'ios_interruption' => 'passive',
            'android_channel' => 'new_posts',
            'android_priority' => 'normal'
        ],
        self::CATEGORY_COLLECTION => [
            'ios_interruption' => 'active',
            'android_channel' => 'reminders',
            'android_priority' => 'normal'
        ],
        self::CATEGORY_EVENT_SUMMARY => [
            'ios_interruption' => 'passive',
            'android_channel' => 'social',
            'android_priority' => 'normal'
        ],
        self::CATEGORY_EXHORT => [
            'ios_interruption' => 'passive',
            'android_channel' => 'tips',
            'android_priority' => 'normal'
        ]
    ];

    private $dbhr, $dbhm, $log, $pheanstalk = NULL, $firebase = NULL, $messaging = NULL;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($dbhr, $dbhm);

        if (file_exists('/etc/firebase.json')) {
            $factory = (new Factory)
                ->withServiceAccount('/etc/firebase.json');
            $this->messaging = $factory->createMessaging();
        }
    }

    public function get($userid)
    {
        # Cache the notification - saves a DB call in GET of session, which is very common.
        $ret = Utils::presdef('notification', $_SESSION, []);

        if (!$ret) {
            $sql = "SELECT * FROM users_push_notifications WHERE userid = ?;";
            $notifs = $this->dbhr->preQuery($sql, [$userid]);
            foreach ($notifs as &$notif) {
                $notif['added'] = Utils::ISODate($notif['added']);
                $ret[] = $notif;
            }

            $_SESSION['notification'] = $ret;
        }

        return ($ret);
    }

    public function add($userid, $type, $val, $modtools = NULL)
    {
        if (is_null($modtools)) {
            $modtools = Session::modtools();
        }

        $rc = NULL;

        if ($userid) {
            $apptype = $modtools ? PushNotifications::APPTYPE_MODTOOLS : PushNotifications::APPTYPE_USER;
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
                $this->pheanstalk = Pheanstalk::create(PHEANSTALK_SERVER);
            }

            $str = json_encode(array(
                'type' => 'webpush',
                'notiftype' => $type,
                'queued' => microtime(TRUE),
                'userid' => $userid,
                'params' => $params,
                'endpoint' => $endpoint,
                'payload' => $payload,
                'ttr' => Utils::PHEANSTALK_TTR
            ));

            $id = $this->pheanstalk->put($str);
        } catch (\Exception $e) {
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
                case PushNotifications::PUSH_BROWSER_PUSH:
                    {
                        # Everything is in one array as passed to this function; split it out into what we need
                        # for FCM.
                        #error_log("FCM notif " . var_export($payload, TRUE));
                        $data = $payload;

                        # We can only have key => string, so the chatids needs to be converted from an array to
                        # a string (if it isn't already).
                        if (is_array($data['chatids'])) {
                            $data['chatids'] = implode(',', $data['chatids']);
                        } else {
                            $data['chatids'] = (string)$data['chatids'];
                        }

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

                            # Build Android config
                            $androidConfig = [
                                'ttl' => '3600s',
                                'priority' => 'normal'
                            ];

                            $category = Utils::presdef('category', $payload, NULL);
                            $hasChannelId = !empty($data['channel_id']);

                            if ($category && isset(self::CATEGORIES[$category])) {
                                $categoryConfig = self::CATEGORIES[$category];
                                $androidConfig['priority'] = $categoryConfig['android_priority'];

                                # NEW notifications (with channel_id): Data-only, app creates notification
                                if ($hasChannelId) {
                                    # Add category to data so app can add action buttons
                                    $data['category'] = $category;
                                    # Update the message with the modified data
                                    $message = CloudMessage::fromArray([
                                        'token' => $endpoint,
                                        'data' => $data
                                    ]);
                                }
                                # LEGACY notifications (no channel_id): Use androidConfig notification for auto-display
                                else {
                                    $androidConfig['notification'] = [
                                        'channel_id' => $categoryConfig['android_channel']
                                    ];

                                    # Add thread tag for notification grouping
                                    $threadId = Utils::presdef('threadId', $payload, NULL);
                                    if ($threadId) {
                                        $androidConfig['notification']['tag'] = $threadId;
                                    }

                                    # Add image for rich notifications
                                    $image = Utils::presdef('image', $payload, NULL);
                                    if ($image && strpos($image, 'http') === 0) {
                                        $androidConfig['notification']['image'] = $image;
                                    }
                                }
                            }

                            $message = $message->withAndroidConfig($androidConfig);
                        } else {
                            # For IOS and browser push notifications.
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
                                    'body' => $iosbody,
                                ];

                                if ($notiftype == PushNotifications::PUSH_BROWSER_PUSH) {
                                    $ios['notification']['webpush'] = [
                                        'fcm_options' => [
                                            'link' => $payload['route']
                                        ]
                                    ];
                                }
                            }

                            #error_log("ios is " . var_export($ios, TRUE));
                            $message = CloudMessage::fromArray($ios);

                            $aps = [
                                'badge' => $payload['count'],
                                'sound' => "default"
                            ];

                            # Add category, interruption-level and thread-id if category is set (iOS 15+)
                            $category = Utils::presdef('category', $payload, NULL);
                            if ($category && isset(self::CATEGORIES[$category])) {
                                $categoryConfig = self::CATEGORIES[$category];
                                # Category is required for iOS action buttons to work
                                $aps['category'] = $category;
                                $aps['interruption-level'] = $categoryConfig['ios_interruption'];

                                # Add thread-id for notification grouping
                                $threadId = Utils::presdef('threadId', $payload, NULL);
                                if ($threadId) {
                                    $aps['thread-id'] = $threadId;
                                }

                                # Add channel_id to data so the app can filter on it
                                $data['channel_id'] = $categoryConfig['android_channel'];
                                $ios['data'] = $data;
                                $message = CloudMessage::fromArray($ios);
                            }

                            # For iOS, add image via mutable-content (requires Notification Service Extension)
                            $image = Utils::presdef('image', $payload, NULL);
                            if ($image && strpos($image, 'http') === 0) {
                                $aps['mutable-content'] = 1;
                                # Image URL goes in data for the Service Extension to fetch
                                $data['imageUrl'] = $image;
                                $ios['data'] = $data;
                                $message = CloudMessage::fromArray($ios);
                            }

                            $params = [
                                'headers' => [
                                    'apns-priority' => '10',
                                ],
                                'payload' => [
                                    'aps' => $aps
                                ],
                            ];

                            #error_log("Send params " . var_export($params, TRUE));
                            #error_log("Send payload " . var_export($ios, TRUE));
                            $message = $message->withApnsConfig($params);
                        }

                        try {
                            if ($this->messaging) {
                                $this->messaging->validate($message);
                            }
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
                                throw new \Exception($errorCode);
                            }

                            foreach ($error['details'] as $detail) {
                                if (array_key_exists('fieldViolations', $detail)) {
                                    if ($detail['fieldViolations'][0]['description'] == 'Invalid registration token') {
                                        # We do want to remove the subscription in this case.
                                        throw new \Exception($detail['fieldViolations'][0]['description']);
                                    }
                                    if ($detail['fieldViolations'][0]['description'] == 'The registration token is not a valid FCM registration token') {
                                        # We do want to remove the subscription in this case.
                                        throw new \Exception($detail['fieldViolations'][0]['description']);
                                    }
                                }
                            }

                            $rc = TRUE; // Problem is ignored and subscription/token NOT removed: eyeball logs to check
                            break;
                        }

                        if ($this->messaging) {
                            $ret = $this->messaging->send($message);
                            error_log("FCM send " . var_export($ret, TRUE));
                        }

                        $rc = TRUE;
                        break;
                    }
                case PushNotifications::PUSH_GOOGLE:
                case PushNotifications::PUSH_FIREFOX:
                    $params = $params ? $params : [];
                    $webPush = new WebPush($params);
                    $subscription = Subscription::create([
                                                           "endpoint" => $endpoint,
                                                       ]);
                    #error_log("Send params " . var_export($params, TRUE) . " " . ($payload['count'] > 0) . "," . (!is_null($payload['title'])));
                    if (($payload && ($payload['count'] > 0) && (!is_null($payload['title'])))) {
                        $rc = $webPush->queueNotification($subscription, $payload['title']);
                    } else
                        $rc = TRUE;
                    break;
            }

            #error_log("Returned " . var_export($rc, TRUE) . " for $userid type $notiftype $endpoint payload " . var_export($payload, TRUE));
            $rc = $this->uthook($rc);
        } catch (\Exception $e) {
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

        return $rc;
    }

    private function notifyIndividualMessages($userid, $notifs, $modtools, $chatid = NULL) {
        // Send individual per-message notifications for admin users (new rich format)
        // If $chatid is specified, only send notifications for that specific chat
        $count = 0;
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $email = $u->getEmailPreferred();

        // Get unread chat messages for this user that haven't been notified yet
        $chatFilter = $chatid ? "AND cm.chatid = ?" : "";
        $params = [$userid, $userid, $userid, $userid];
        if ($chatid) {
            $params[] = $chatid;
        }

        // Only check lastmsgnotified, not lastmsgseen - we want to send notifications even if the app
        // has marked messages as "seen" (which can happen due to background polling or incorrect client logic)
        $chats = $this->dbhr->preQuery("
            SELECT cm.id, cm.chatid, cm.userid as senderid, cm.message, cm.date
            FROM chat_messages cm
            INNER JOIN chat_rooms cr ON cm.chatid = cr.id
            LEFT JOIN chat_roster roster ON roster.chatid = cm.chatid AND roster.userid = ?
            WHERE (cr.user1 = ? OR cr.user2 = ?)
            AND cm.userid != ?
            AND cm.reviewrequired = 0
            AND cm.reviewrejected = 0
            AND cm.id > COALESCE(roster.lastmsgnotified, 0)
            $chatFilter
            ORDER BY cm.date ASC
            LIMIT 20
        ", $params);

        $lastMsgId = 0;
        $chatidsNotified = [];

        foreach ($chats as $chat) {
            // Get chat room info including icon
            $r = new ChatRoom($this->dbhr, $this->dbhm, $chat['chatid']);
            $atts = $r->getPublic($u);
            $icon = Utils::presdef('icon', $atts, USERLOGO);

            // Get the other user's name (the sender)
            if (isset($atts['user1']) && $atts['user1']['id'] == $chat['senderid']) {
                $sendername = $atts['user1']['displayname'];
            } elseif (isset($atts['user2']) && $atts['user2']['id'] == $chat['senderid']) {
                $sendername = $atts['user2']['displayname'];
            } else {
                $sendername = 'Someone';
            }

            $message = Utils::decodeEmojis($chat['message']);
            $messagePreview = strlen($message) > 50 ? (substr($message, 0, 50) . "...") : $message;
            $message = strlen($message) > 256 ? (substr($message, 0, 256) . "...") : $message;

            error_log("Notify push chat #{$chat['chatid']} $email for $userid message {$chat['id']}: $messagePreview");

            // Get channel configuration for this category
            $categoryConfig = self::CATEGORIES[self::CATEGORY_CHAT_MESSAGE];

            $payload = [
                'badge' => count($chats),
                'count' => count($chats),
                'chatcount' => count($chats),
                'notifcount' => 0,
                'title' => $sendername,
                'message' => $message,
                'chatids' => [(string)$chat['chatid']],  // Array with single chat ID for implode compatibility
                'chatid' => (string)$chat['chatid'],  // Individual chat ID for this message
                'messageid' => (string)$chat['id'],    // Message ID for uniqueness
                // Use chatid for notId so new messages replace old ones for the same chat, preventing notification flooding
                // even when sending per-message notifications. Each chat gets one notification slot that updates with latest message.
                'notId' => (int)$chat['chatid'],
                'timestamp' => strtotime($chat['date']), // Unix timestamp for sorting
                'content-available' => 1,
                'image' => $icon,
                'modtools' => $modtools,
                'sound' => 'default',
                'route' => '/chats/' . $chat['chatid'],
                'category' => self::CATEGORY_CHAT_MESSAGE,
                'channel_id' => $categoryConfig['android_channel'],  // Required for Android to use correct channel
                'threadId' => 'chat_' . $chat['chatid']
            ];

            foreach ($notifs as $notif) {
                $this->queueSend($userid, $notif['type'], [], $notif['subscription'], $payload);
                $count++;
            }

            // Track the highest message ID and chats we've notified
            $lastMsgId = max($lastMsgId, $chat['id']);
            if (!in_array($chat['chatid'], $chatidsNotified)) {
                $chatidsNotified[] = $chat['chatid'];
            }
        }

        // Update lastmsgnotified for each chat we sent notifications for
        foreach ($chatidsNotified as $cid) {
            $this->dbhm->preExec("
                INSERT INTO chat_roster (chatid, userid, lastmsgnotified, date)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE lastmsgnotified = ?
            ", [$cid, $userid, $lastMsgId, $lastMsgId]);
        }

        return $count;
    }

    public function notify($userid, $modtools, $browserPush = FALSE, $chatid = NULL)
    {
        $count = 0;
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $proceedpush = TRUE; // $u->notifsOn(User::NOTIFS_PUSH);
        $proceedapp = $u->notifsOn(User::NOTIFS_APP);
        #error_log("Notify $userid, push on $proceedpush app on $proceedapp MT $modtools browserPush $browserPush chatid $chatid");

        if ($browserPush) {
            $notifs = $this->dbhr->preQuery("SELECT * FROM users_push_notifications WHERE userid = ? AND `type` = ?;", [
                $userid,
                PushNotifications::PUSH_BROWSER_PUSH
            ]);
        } else {
            $notifs = $this->dbhr->preQuery("SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?;", [
                $userid,
                $modtools ? PushNotifications::APPTYPE_MODTOOLS : PushNotifications::APPTYPE_USER
            ]);
        }

        // Send individual per-message notifications (new rich format with action buttons)
        // This handles chat messages with rich formatting
        if (!$modtools && $proceedapp) {
            $appNotifs = array_filter($notifs, function($n) {
                return $n['type'] === PushNotifications::PUSH_FCM_ANDROID || $n['type'] === PushNotifications::PUSH_FCM_IOS;
            });

            if (count($appNotifs) > 0) {
                $individualCount = $this->notifyIndividualMessages($userid, $appNotifs, $modtools, $chatid);
                if ($individualCount > 0) {
                    // We sent chat message notifications, so we're done
                    return $individualCount;
                }
                // If no chat messages were found, fall through to legacy notification path
                // to handle users_notifications (TYPE_EXHORT, etc.)
            }
        }

        foreach ($notifs as $notif) {
            #error_log("Consider notif {$notif['id']} proceed $proceedpush type {$notif['type']}");
            if ($proceedpush && in_array($notif['type'],
                    [PushNotifications::PUSH_FIREFOX, PushNotifications::PUSH_GOOGLE, PushNotifications::PUSH_BROWSER_PUSH]) ||
                ($proceedapp && in_array($notif['type'],
                        [PushNotifications::PUSH_FCM_ANDROID, PushNotifications::PUSH_FCM_IOS]))) {
                #error_log("Send user $userid {$notif['subscription']} type {$notif['type']} for modtools $modtools");
                $payload = NULL;
                $params = [];

                list ($total, $chatcount, $notifscount, $title, $message, $chatids, $route, $category, $threadId, $image) = $u->getNotificationPayload($modtools);

                if ($title || $modtools || $total === 0) {
                    $message = ($total === 0) ? "" : $message;
                    if (is_null($message)) $message = "";

                    # badge and/or count are used by the app, possibly when it isn't running, to set the home screen badge.
                    $basePayload = [
                        'badge' => $total,
                        'count' => $total,
                        'chatcount' => $chatcount,
                        'notifcount' => $notifscount,
                        'title' => $title,
                        'message' => $message,
                        'chatids' => $chatids,
                        'content-available' => $total > 0,
                        'image' => $image ? $image : ($modtools ? "www/images/modtools_logo.png" : "www/images/user_logo.png"),
                        'modtools' => $modtools,
                        'sound' => 'default',
                        'route' => $route,
                        'threadId' => $threadId
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

                    # For mobile apps (Android/iOS), send TWO notifications:
                    # 1. Legacy notification (no channel_id) - for old app versions
                    # 2. New notification (with channel_id) - for new app versions
                    # Each app version filters to only process one type, so no duplicates shown.
                    $isAppNotification = in_array($notif['type'], [PushNotifications::PUSH_FCM_ANDROID, PushNotifications::PUSH_FCM_IOS]);

                    if ($isAppNotification && $category) {
                        # Send legacy notification first (no category/channel_id)
                        $legacyPayload = $basePayload;
                        $this->queueSend($userid, $notif['type'], $params, $notif['subscription'], $legacyPayload);
                        $count++;

                        # Send new notification (with category/channel_id)
                        $newPayload = $basePayload;
                        $newPayload['category'] = $category;
                        $this->queueSend($userid, $notif['type'], $params, $notif['subscription'], $newPayload);
                        $count++;
                    } else {
                        # Browser push or no category - send single notification
                        $payload = $basePayload;
                        if ($category) {
                            $payload['category'] = $category;
                        }
                        $this->queueSend($userid, $notif['type'], $params, $notif['subscription'], $payload);
                        $count++;
                    }
                }
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
                #error_log("Notify {$mod['userid']} for $groupid notify " . Utils::presdef('pushnotify', $settings, TRUE) . " settings " . var_export($settings, TRUE));
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
                $this->pheanstalk = Pheanstalk::create(PHEANSTALK_SERVER);
            }

            $str = json_encode(array(
                'type' => 'poke',
                'queued' => microtime(TRUE),
                'groupid' => $userid,
                'data' => $data,
                'modtools' => $modtools,
                'ttr' => Utils::PHEANSTALK_TTR
            ));

            $this->pheanstalk->put($str);
            $ret = TRUE;
        } catch (\Exception $e) {
            error_log("poke Beanstalk exception " . $e->getMessage());
            $this->pheanstalk = NULL;
            $ret = FALSE;
        }

        return ($ret);
    }

    public function executePoke($userid, $data, $modtools)
    {
        # This kicks a user who is online at the moment with an outstanding long poll.
        Utils::filterResult($data);

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

        # Currently we don't do anything with these.  We used to send them to an nchan instance,
        # but that was no longer reliable and the clients don't use them.
        if (CHAT_HOST && FALSE) {
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
            } catch (\Exception $e) {
                error_log("Failed to notify");
            }
        }
    }
}
