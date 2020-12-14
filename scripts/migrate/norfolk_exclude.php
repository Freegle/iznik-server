<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/spam/Spam.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
require_once(IZNIK_BASE . '/include/misc/Location.php');
require_once(IZNIK_BASE . '/include/misc/PAF.php');

# Stats
$users_known = 0;
$users_new = 0;
$users_noemail = 0;
$postcode_mapped = 0;
$postcode_untouched = 0;
$postcode_failed = 0;
$address_mapped = 0;
$address_failed = 0;
$emaildaily = 0;
$emailnever = 0;
$emailimmediate = 0;
$posts = 0;

$dsn = "mysql:host={$dbconfig['host']};dbname=Norfolk;charset=utf8";

$dbhn = new LoggedPDO($dsn, $dbconfig['user'], $dbconfig['pass']);

$start = date('Y-m-d', strtotime("30 years ago"));
$alluserssql = "SELECT * FROM u_User
   WHERE u_IsActive = 0 OR u_DontDelete != 0;";

$users = $dbhn->preQuery($alluserssql);
$total = count($users);
$count = 0;
$u = new User($dbhr, $dbhm);

error_log("Migrate $total users\n");

# First migrate across all the users.
foreach ($users as $user) {
    if ($user['u_NickName'] != 'System') {
        # error_log("{$user['u_Id']} {$user['u_NickName']}");

        # Get email.  Use the most recent.
        $sql = "SELECT * FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 AND ue_IsActivated = 1 AND ue_AddressProblem = 0 ORDER BY ue_ModifiedDt DESC LIMIT 1;";
        #$sql = "SELECT * FROM ue_UserEmail WHERE ue_u_Id = ? AND ue_IsLogon = 1 ORDER BY ue_ModifiedDt DESC LIMIT 1;";
        $emails = $dbhn->preQuery($sql, [
            $user['u_Id']
        ]);

        if (count($emails)) {
            $uid = NULL;

            foreach ($emails as $email) {
                $e = str_replace('.NERFED', '', $email['ue_EmailAddress']);
                $uid = $u->findByEmail($e);

                if ($uid) {
                    # The user already exists.  Don't touch them - if they're actively using FD already then we don't
                    # want to confuse matters by changing their login.
                    $users_known++;
                    $s = new Spam($dbhr, $dbhm);
                    error_log("...found $e as #$uid, is spammer " . $s->isSpammer($email['ue_EmailAddress']));
                }
            }
        }
    }
}
