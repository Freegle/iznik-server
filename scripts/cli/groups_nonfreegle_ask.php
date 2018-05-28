<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/misc/Stats.php');

$groups = $dbhr->preQuery("SELECT * FROM groups WHERE type != 'Freegle' AND licenseduntil > NOW() ORDER BY nameshort ASC ;");
list ($transport, $mailer) = getMailer();

foreach ($groups as $group) {
    $body = "Hi there,
    
    Thanks for using ModTools.  As I've said in https://uk.groups.yahoo.com/neo/groups/ModTools/conversations/messages/53075, I will no longer be supporting ModTools for Yahoo Groups after September 2019.
    
    You currently have a license for ModTools.  If you are no longer using it on {$group['nameshort']}, could you reply to let me know, and I'll remove the group?
    
    If you are intending to use it after September 2018, please also reply to let me know that.
    
    This will help me work out how much work I need to do!
    
    Regards,
    
    Edward";

    $message = Swift_Message::newInstance()
        ->setSubject("ModTools - are you still using it?")
        ->setFrom(MODERATOR_EMAIL)
        ->setTo($group['nameshort'] . '-owner@yahoogroups.com')
        ->setReplyTo('edward@ehibbert.org.uk')
        ->setDate(time())
        ->setBody($body);
    $mailer->send($message);
    sleep(300);
}
