<?php
namespace Freegle\Iznik;

/**
 * Handles push notifications about new OFFER/WANTED posts.
 *
 * This class sends push notifications to app users about new posts on their groups,
 * respecting the same frequency settings as email digests (immediate, hourly, daily, etc.).
 *
 * Key behaviors:
 * - Only sends NEW style notifications (with channel_id) - old apps won't process these
 * - Respects user's emailfrequency setting per group
 * - Summarizes multiple posts into a single notification to avoid spam
 * - Excludes posts that are already taken/received/promised
 * - Groups notifications by frequency (immediate = per-message, others = batched)
 */
class PostNotifications
{
    private $dbhr, $dbhm;

    // Maximum posts to list in a notification summary
    const MAX_POSTS_IN_NOTIFICATION = 5;

    // Minimum interval between notifications for non-immediate frequencies (in seconds)
    // This prevents sending too many notifications if the cron runs frequently
    const MIN_NOTIFICATION_INTERVAL = 300; // 5 minutes

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    /**
     * Send post notifications for a group at a specific frequency.
     *
     * @param int $groupid The group to process
     * @param int $frequency The frequency constant (Digest::IMMEDIATE, Digest::DAILY, etc.)
     * @return int Number of notifications sent
     */
    public function send($groupid, $frequency)
    {
        $sent = 0;
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);

        // Don't send for closed groups
        if ($g->getSetting('closed', FALSE)) {
            return 0;
        }

        // Check if we should send now based on frequency
        if (!$this->shouldSendNow($groupid, $frequency)) {
            return 0;
        }

        // Get new posts since last notification
        $posts = $this->getNewPosts($groupid, $frequency);

        if (count($posts) == 0) {
            return 0;
        }

        // Get users who want notifications at this frequency and have app subscriptions
        $users = $this->getUsersForNotification($groupid, $frequency);

        foreach ($users as $user) {
            $sent += $this->sendToUser($user['userid'], $posts, $g, $frequency);
        }

        // Update tracking
        if (count($posts) > 0) {
            $this->updateTracking($groupid, $frequency, $posts);
        }

        return $sent;
    }

    /**
     * Check if enough time has passed since the last notification.
     */
    private function shouldSendNow($groupid, $frequency)
    {
        // For immediate, always send
        if ($frequency == Digest::IMMEDIATE) {
            return TRUE;
        }

        // For other frequencies, check if enough time has passed
        $sql = "SELECT TIMESTAMPDIFF(SECOND, lastsent, NOW()) AS secondsago
                FROM users_postnotifications_tracking
                WHERE groupid = ? AND frequency = ?;";
        $tracks = $this->dbhr->preQuery($sql, [$groupid, $frequency]);

        if (count($tracks) == 0) {
            // Never sent before
            return TRUE;
        }

        $secondsAgo = $tracks[0]['secondsago'];

        // Check if we've waited long enough based on frequency
        // frequency is in hours, convert to seconds
        $requiredSeconds = max($frequency * 3600, self::MIN_NOTIFICATION_INTERVAL);

        return $secondsAgo >= $requiredSeconds;
    }

    /**
     * Get new posts that haven't been notified about yet.
     */
    private function getNewPosts($groupid, $frequency)
    {
        // Get the last message date we notified about
        $sql = "SELECT msgdate FROM users_postnotifications_tracking WHERE groupid = ? AND frequency = ?;";
        $tracks = $this->dbhr->preQuery($sql, [$groupid, $frequency]);

        $lastMsgDate = count($tracks) > 0 && $tracks[0]['msgdate']
            ? $tracks[0]['msgdate']
            : NULL;

        // Build query for new posts
        // - Only OFFER and WANTED types
        // - Only approved messages
        // - Not deleted
        // - From non-deleted users
        // - No outcomes (not taken/received/promised)
        // - Newer than last notification
        $msgDateCondition = $lastMsgDate
            ? " AND messages_groups.arrival > '$lastMsgDate'"
            : " AND messages_groups.arrival >= '" . date("Y-m-d H:i:s", strtotime("24 hours ago")) . "'";

        // For immediate, we only want messages from the last hour to avoid flooding on first run
        if ($frequency == Digest::IMMEDIATE && !$lastMsgDate) {
            $msgDateCondition = " AND messages_groups.arrival >= '" . date("Y-m-d H:i:s", strtotime("1 hour ago")) . "'";
        }

        $sql = "SELECT messages.id, messages.subject, messages.type, messages.fromuser,
                       messages_groups.arrival, messages.availablenow
                FROM messages_groups
                INNER JOIN messages ON messages.id = messages_groups.msgid
                INNER JOIN users ON users.id = messages.fromuser
                LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id
                WHERE messages_groups.groupid = ?
                  AND messages_groups.collection = ?
                  AND messages_groups.deleted = 0
                  AND users.deleted IS NULL
                  AND messages.type IN (?, ?)
                  AND messages_outcomes.msgid IS NULL
                  $msgDateCondition
                ORDER BY messages_groups.arrival ASC;";

        $posts = $this->dbhr->preQuery($sql, [
            $groupid,
            MessageCollection::APPROVED,
            Message::TYPE_OFFER,
            Message::TYPE_WANTED
        ]);

        return $posts;
    }

    /**
     * Get users who have app push notifications and want this frequency.
     */
    private function getUsersForNotification($groupid, $frequency)
    {
        // Find users who:
        // 1. Are members of this group
        // 2. Have emailfrequency matching the requested frequency
        // 3. Have app push notification subscriptions (FCM Android or iOS)
        // 4. Have app notifications enabled
        // 5. TEMPORARILY: Are Admins only (remove this filter after testing)
        $sql = "SELECT DISTINCT m.userid
                FROM memberships m
                INNER JOIN users_push_notifications upn ON upn.userid = m.userid
                INNER JOIN users u ON u.id = m.userid
                WHERE m.groupid = ?
                  AND m.emailfrequency = ?
                  AND upn.type IN (?, ?)
                  AND upn.apptype = ?
                  AND u.deleted IS NULL
                  AND u.systemrole = ?;";

        $users = $this->dbhr->preQuery($sql, [
            $groupid,
            $frequency,
            PushNotifications::PUSH_FCM_ANDROID,
            PushNotifications::PUSH_FCM_IOS,
            PushNotifications::APPTYPE_USER,
            User::SYSTEMROLE_ADMIN
        ]);

        return $users;
    }

    /**
     * Send notification to a specific user.
     */
    public function sendToUser($userid, $posts, $g, $frequency)
    {
        // Don't notify users about their own posts
        $filteredPosts = array_filter($posts, function($post) use ($userid) {
            return $post['fromuser'] != $userid;
        });

        if (count($filteredPosts) == 0) {
            return 0;
        }

        $filteredPosts = array_values($filteredPosts);
        $postCount = count($filteredPosts);
        $groupName = $g->getPublic()['namedisplay'];

        // Build notification content
        if ($postCount == 1) {
            // Single post - show details
            $post = $filteredPosts[0];
            $title = $post['subject'];
            $message = "New " . strtolower($post['type']) . " on $groupName";
            $route = "/message/" . $post['id'];
            $threadId = 'post_' . $post['id'];
        } else {
            // Multiple posts - summarize
            $offerCount = count(array_filter($filteredPosts, function($p) {
                return $p['type'] == Message::TYPE_OFFER;
            }));
            $wantedCount = $postCount - $offerCount;

            $parts = [];
            if ($offerCount > 0) {
                $parts[] = "$offerCount OFFER" . ($offerCount > 1 ? "s" : "");
            }
            if ($wantedCount > 0) {
                $parts[] = "$wantedCount WANTED" . ($wantedCount > 1 ? "s" : "");
            }

            $title = implode(" and ", $parts) . " on $groupName";

            // Build message with up to MAX_POSTS_IN_NOTIFICATION items
            $items = array_slice($filteredPosts, 0, self::MAX_POSTS_IN_NOTIFICATION);
            $itemNames = array_map(function($p) {
                // Extract item name from subject (format: "TYPE: Item (Location)")
                $subject = $p['subject'];
                if (preg_match('/^(?:OFFER|WANTED):\s*(.+?)(?:\s*\(.*\))?$/i', $subject, $matches)) {
                    return trim($matches[1]);
                }
                return $subject;
            }, $items);

            $message = implode(", ", $itemNames);
            if ($postCount > self::MAX_POSTS_IN_NOTIFICATION) {
                $remaining = $postCount - self::MAX_POSTS_IN_NOTIFICATION;
                $message .= " + $remaining more";
            }

            $route = "/browse";
            $threadId = 'posts_' . $g->getId();
        }

        // Get first post's image if available
        $image = NULL;
        if (count($filteredPosts) > 0) {
            $m = new Message($this->dbhr, $this->dbhm, $filteredPosts[0]['id']);
            $atts = $m->getPublic(FALSE, TRUE, TRUE);
            if (count($atts['attachments']) > 0) {
                $image = $atts['attachments'][0]['path'];
            }
        }

        // Queue the notification - only new style (with category)
        $n = new PushNotifications($this->dbhr, $this->dbhm);

        // Get user's push subscriptions
        $notifs = $this->dbhr->preQuery(
            "SELECT * FROM users_push_notifications WHERE userid = ? AND type IN (?, ?) AND apptype = ?;",
            [$userid, PushNotifications::PUSH_FCM_ANDROID, PushNotifications::PUSH_FCM_IOS, PushNotifications::APPTYPE_USER]
        );

        $count = 0;
        foreach ($notifs as $notif) {
            $payload = [
                'badge' => $postCount,
                'count' => $postCount,
                'chatcount' => 0,
                'notifcount' => 0,
                'title' => $title,
                'message' => $message,
                'chatids' => [],
                'content-available' => TRUE,
                'image' => $image,
                'modtools' => FALSE,
                'sound' => 'default',
                'route' => $route,
                'threadId' => $threadId,
                'category' => PushNotifications::CATEGORY_NEW_POSTS
            ];

            $this->queueSend($userid, $notif['type'], [], $notif['subscription'], $payload);
            $count++;
        }

        return $count;
    }

    /**
     * Queue notification for sending.
     * Split out for unit test mocking.
     */
    public function queueSend($userid, $type, $params, $endpoint, $payload)
    {
        $n = new PushNotifications($this->dbhr, $this->dbhm);

        // Use reflection to call the private queueSend method
        // Or we can just queue it directly via Pheanstalk
        try {
            $pheanstalk = \Pheanstalk\Pheanstalk::create(PHEANSTALK_SERVER);

            $str = json_encode([
                'type' => 'webpush',
                'notiftype' => $type,
                'queued' => microtime(TRUE),
                'userid' => $userid,
                'params' => $params,
                'endpoint' => $endpoint,
                'payload' => $payload,
                'ttr' => Utils::PHEANSTALK_TTR
            ]);

            $pheanstalk->put($str);
        } catch (\Exception $e) {
            error_log("PostNotifications Beanstalk exception " . $e->getMessage());
        }
    }

    /**
     * Update tracking for when we last sent notifications.
     */
    private function updateTracking($groupid, $frequency, $posts)
    {
        // Get the latest message arrival time
        $maxDate = NULL;
        foreach ($posts as $post) {
            if ($maxDate === NULL || $post['arrival'] > $maxDate) {
                $maxDate = $post['arrival'];
            }
        }

        $sql = "INSERT INTO users_postnotifications_tracking (groupid, frequency, msgdate, lastsent)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE msgdate = ?, lastsent = NOW();";
        $this->dbhm->preExec($sql, [$groupid, $frequency, $maxDate, $maxDate]);
    }

    /**
     * Process all groups for a given frequency.
     * Called by cron job.
     */
    public function processAllGroups($frequency)
    {
        $sent = 0;

        // Get all active Freegle groups
        $sql = "SELECT id FROM `groups`
                WHERE type = ?
                  AND publish = 1
                  AND onhere = 1
                ORDER BY id;";
        $groups = $this->dbhr->preQuery($sql, [Group::GROUP_FREEGLE]);

        foreach ($groups as $group) {
            $sent += $this->send($group['id'], $frequency);
        }

        return $sent;
    }
}
