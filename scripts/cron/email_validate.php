<?php


namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$emails = $dbhr->preQuery("SELECT users_emails.id, email, userid FROM users_emails INNER JOIN users ON users.id = users_emails.userid WHERE bouncing = 0;");
$total = count($emails);
$count = 0;
$invalid = 0;

$lockh = Utils::lockScript(basename(__FILE__));

try {
    foreach ($emails as $email) {
        if (!preg_match(Message::EMAIL_REGEXP, $email['email'])) {
            error_log("{$email['email']} for {$email['userid']}");
            $invalid++;
            $dbhm->preExec("DELETE FROM users_emails WHERE id = ?;", [
                $email['id']
            ]);
        }

        $count++;

        if ($count % 1000 == 0) {
            error_log("...$count / $total");
        }
    }
} catch (\Exception $e) {
    error_log("Delete index failed with " . $e->getMessage());
    \Sentry\captureException($e);
}

error_log("\nFound $invalid invalid in $total");
Utils::unlockScript($lockh);