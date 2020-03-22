<?php

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
$twig = new Twig_Environment($loader);

$users = $dbhr->preQuery("SELECT covid_matches.*, u1.deleted, u2.deleted FROM covid_matches INNER JOIN users u1 ON u1.id = covid_matches.helper INNER JOIN users u2 ON u2.id = covid_matches.helpee WHERE u1.deleted IS NULL AND u2.deleted IS NULL;");

$count = 0;

foreach ($users as $user) {
    #error_log("{$user['helper']} => {$user['helpee']}");
    $chats = $dbhr->preQuery("SELECT * FROM chat_rooms WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?);", [
        $user['helper'],
        $user['helpee'],
        $user['helpee'],
        $user['helper']
    ]);

    foreach ($chats as $chat) {
        #error_log("...chat exists");
        $msgs = $dbhr->preQuery("SELECT * FROM chat_messages WHERE chatid = ? AND date > ?;", [
            $chat['id'],
            $user['suggestedat']
        ]);

        foreach ($msgs as $msg) {
            error_log("{$user['helper']} => {$user['helpee']}...{$msg['message']}");
        }
    }
}