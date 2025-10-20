<?php
namespace Freegle\Iznik;

class IPBlocker
{
    const BLOCK_DIR = '/etc/iznik_ip_blocking/blocked';
    const WHITELIST_FILE = '/etc/iznik_ip_blocking/whitelist.txt';
    const INITIAL_BLOCK_DURATION = 3600; // 1 hour in seconds
    const MAX_BLOCK_DURATION = 604800; // 7 days in seconds

    private $dbhr;
    private $dbhm;

    public function __construct($dbhr, $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->ensureDirectories();
    }

    private function ensureDirectories()
    {
        if (!file_exists(self::BLOCK_DIR)) {
            @mkdir(self::BLOCK_DIR, 0755, TRUE);
        }

        if (!file_exists(dirname(self::WHITELIST_FILE))) {
            @mkdir(dirname(self::WHITELIST_FILE), 0755, TRUE);
        }

        if (!file_exists(self::WHITELIST_FILE)) {
            @touch(self::WHITELIST_FILE);
        }
    }

    public function isWhitelisted($ip)
    {
        if (!file_exists(self::WHITELIST_FILE)) {
            return FALSE;
        }

        $whitelist = file_get_contents(self::WHITELIST_FILE);
        $lines = explode("\n", $whitelist);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || substr($line, 0, 1) === '#') {
                continue;
            }

            if ($line === $ip) {
                return TRUE;
            }
        }

        return FALSE;
    }

    public function isBlocked($ip)
    {
        if ($this->isWhitelisted($ip)) {
            return FALSE;
        }

        $blockFile = $this->getBlockFilePath($ip);

        if (!file_exists($blockFile)) {
            return FALSE;
        }

        $data = json_decode(file_get_contents($blockFile), TRUE);

        if (!$data) {
            @unlink($blockFile);
            return FALSE;
        }

        $blockedUntil = $data['blocked_until'];

        if (time() >= $blockedUntil) {
            @unlink($blockFile);
            return FALSE;
        }

        return TRUE;
    }

    public function blockIP($ip, $reason, $userid = NULL, $username = NULL, $email = NULL)
    {
        if ($this->isWhitelisted($ip)) {
            return FALSE;
        }

        $blockFile = $this->getBlockFilePath($ip);
        $blockCount = 0;
        $duration = self::INITIAL_BLOCK_DURATION;
        $isNewBlock = !file_exists($blockFile);

        if (file_exists($blockFile)) {
            $existingData = json_decode(file_get_contents($blockFile), TRUE);
            if ($existingData) {
                $blockCount = $existingData['block_count'];
            }
        }

        $blockCount++;

        for ($i = 1; $i < $blockCount; $i++) {
            $duration *= 2;
            if ($duration > self::MAX_BLOCK_DURATION) {
                $duration = self::MAX_BLOCK_DURATION;
                break;
            }
        }

        $blockedUntil = time() + $duration;

        $data = [
            'ip' => $ip,
            'blocked_at' => time(),
            'blocked_until' => $blockedUntil,
            'duration' => $duration,
            'block_count' => $blockCount,
            'reason' => $reason,
            'userid' => $userid,
            'username' => $username,
            'email' => $email
        ];

        file_put_contents($blockFile, json_encode($data, JSON_PRETTY_PRINT));

        if ($isNewBlock) {
            $this->sendBlockNotification($ip, $reason, $duration, $blockCount, $userid, $username, $email);
        }

        return TRUE;
    }

    public function unblockIP($ip)
    {
        $blockFile = $this->getBlockFilePath($ip);

        if (file_exists($blockFile)) {
            @unlink($blockFile);
            return TRUE;
        }

        return FALSE;
    }

    public function addToWhitelist($ip)
    {
        if ($this->isWhitelisted($ip)) {
            return FALSE;
        }

        file_put_contents(self::WHITELIST_FILE, $ip . "\n", FILE_APPEND);
        return TRUE;
    }

    public function removeFromWhitelist($ip)
    {
        if (!file_exists(self::WHITELIST_FILE)) {
            return FALSE;
        }

        $whitelist = file_get_contents(self::WHITELIST_FILE);
        $lines = explode("\n", $whitelist);
        $newLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== $ip) {
                $newLines[] = $line;
            }
        }

        file_put_contents(self::WHITELIST_FILE, implode("\n", $newLines));
        return TRUE;
    }

    public function getBlockInfo($ip)
    {
        $blockFile = $this->getBlockFilePath($ip);

        if (!file_exists($blockFile)) {
            return NULL;
        }

        $data = json_decode(file_get_contents($blockFile), TRUE);

        if (!$data) {
            return NULL;
        }

        if (time() >= $data['blocked_until']) {
            @unlink($blockFile);
            return NULL;
        }

        return $data;
    }

    public function cleanupExpired()
    {
        if (!is_dir(self::BLOCK_DIR)) {
            return 0;
        }

        $cleaned = 0;
        $files = scandir(self::BLOCK_DIR);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = self::BLOCK_DIR . '/' . $file;

            if (!is_file($filePath)) {
                continue;
            }

            $data = json_decode(file_get_contents($filePath), TRUE);

            if (!$data || time() >= $data['blocked_until']) {
                @unlink($filePath);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    private function getBlockFilePath($ip)
    {
        $safeIP = str_replace('.', '_', $ip);
        $safeIP = str_replace(':', '_', $safeIP);
        return self::BLOCK_DIR . '/' . $safeIP . '.json';
    }

    private function sendBlockNotification($ip, $reason, $duration, $blockCount, $userid, $username, $email)
    {
        $durationHuman = $this->formatDuration($duration);
        $timestamp = date('Y-m-d H:i:s');

        $body = "IP ADDRESS BLOCKED\n\n";
        $body .= "IP Address: $ip\n";
        $body .= "Blocked Until: " . date('Y-m-d H:i:s', time() + $duration) . "\n";
        $body .= "Block Duration: $durationHuman\n";
        $body .= "Block Count: $blockCount\n";
        $body .= "Reason: $reason\n";
        $body .= "Timestamp: $timestamp\n\n";

        if ($userid) {
            $body .= "User ID: $userid\n";
        }
        if ($username) {
            $body .= "Username: $username\n";
        }
        if ($email) {
            $body .= "Email: $email\n";
        }

        try {
            list ($transport, $mailer) = Mail::getMailer();
            $message = \Swift_Message::newInstance()
                ->setSubject("IP Address Blocked: $ip")
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setTo(['log@ehibbert.org.uk'])
                ->setBody($body);

            Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::ALERT, $userid);
            $mailer->send($message);
        } catch (\Exception $e) {
            error_log("Failed to send IP block notification: " . $e->getMessage());
        }
    }

    private function formatDuration($seconds)
    {
        if ($seconds < 3600) {
            return round($seconds / 60) . ' minutes';
        } else if ($seconds < 86400) {
            return round($seconds / 3600) . ' hours';
        } else {
            return round($seconds / 86400) . ' days';
        }
    }
}
