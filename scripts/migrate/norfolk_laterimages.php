<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
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

# User filter for testing this before we go live.
$userfilt = " AND u_Id IN (9, 11, 54) ";
$userfilt = " AND u_Moderator = 1 ";
$userfilt = "";

# Whether we're doing a test migration i.e. no actual data change.
$test = FALSE;

$dsn = "mysql:host={$dbconfig['host']};dbname=Norfolk;charset=utf8";

$dbhn = new LoggedPDO($dsn, $dbconfig['user'], $dbconfig['pass']);

# Get the posts, including by users we're not migrating, for stats purposes.
$posts = $dbhn->preQuery("SELECT DISTINCT ue_EmailAddress, p_Post.p_Id, p_Post.p_DatePosted, p_Post.p_DateClosed, p_Post.p_u_Id, mp_PostStatus.mp_Status, mp_PostStatus.mp_Desc,
       mc_Condition.mc_Desc, p_Post.p_ShortDesc, p_Post.p_Description, mt_PostType.mt_Type,
       ul_PostCode, ul_Longitude, ul_Latitude
FROM p_Post
INNER JOIN u_User ON u_User.u_Id = p_Post.p_u_Id $userfilt
INNER JOIN mt_PostType ON mt_PostType.mt_Id = p_Post.p_mt_PostType
INNER JOIN mp_PostStatus ON mp_PostStatus.mp_Id = p_Post.p_mp_Id
LEFT JOIN mc_Condition ON mc_Condition.mc_Condition = p_Post.p_mc_Condition
LEFT JOIN ue_UserEmail ON ue_UserEmail.ue_u_Id = p_Post.p_u_Id
LEFT JOIN pl_PostLocation ON pl_PostLocation.pl_p_Id = p_Post.p_Id
LEFT JOIN ul_UserLocation ON pl_PostLocation.pl_ul_Id = ul_UserLocation.ul_Id
WHERE mp_PostStatus.mp_Status IN ('o', 'a', 'c')
ORDER BY p_DatePosted DESC");

$postcount = 0;
$photocount = 0;
$postfail = 0;
$withdrawn = 0;
$taken = 0;
$received = 0;

$u = new User($dbhr, $dbhm);

foreach ($posts as $post) {
    error_log("{$post['p_Id']} {$post['ue_EmailAddress']} {$post['p_ShortDesc']}");
    $mid = NULL;
    $postcount++;

    # See if we've migrated already.
    $msgs = $dbhm->preQuery("SELECT id FROM messages WHERE messageid = ?;", [
        "Norfolk-{$post['p_Id']}"
    ]);

    if (count($msgs)) {
        error_log("...already migrated");
        $mid = $msgs[0]['id'];

        # Add any images.
        $images = $dbhn->preQuery("SELECT * FROM pi_PostImage WHERE pi_p_Id = ?;", [
            $post['p_Id']
        ]);

        foreach ($images as $image) {
            error_log("...image {$image['pi_Filename']}");
            $data = @file_get_contents('/tmp/laterimages/' . $image['pi_Filename']);

            if ($data) {
                $a = new Attachment($dbhr, $dbhm);
                $aid = $a->create($mid, 'image/jpg', $data);

                # Archive so we don't flood the DB.
                $a->archive();
            }
        }
    }
}