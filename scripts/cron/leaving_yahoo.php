<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
$twig = new Twig_Environment($loader);

$groups = $dbhr->preQuery("SELECT id FROM groups WHERE onyahoo = 1 AND publish = 1 AND type = ? AND nameshort NOT LIKE 'southwark_freegle' ORDER BY LOWER(nameshort) ASC;", [
    Group::GROUP_FREEGLE
]);

error_log(count($groups) . " groups still on Yahoo\r\n\r\n");

$text = "It's time to move your group away from Yahoo.

If you follow Central you will know that Yahoo's bugs and problems are costing Freegle £4000 to £8000 per year for the work needed to be undertaken by our geeks to keep the two platforms running. Yahoo do not fix their constant stream of never ending bugs so much time is wasted keeping Freegle Direct and Yahoo operating in sync.  It also means that we cannot develop the site in ways that members would like such as being able to edit posts.  This is not sustainable.

Therefore, at the AGM in September 2017, it was agreed that Freegle would drop support for Yahoo Groups after one year had elapsed. From 30th September 2018 Freegle will not support Yahoo for the main groups. Cafes, mod groups and Central will not change at this time.

Many groups are currently moving to native hosting on our own website Freegle Direct to beat this deadline. Around half of all Freegle groups have already moved away from Yahoo.

The Mentor team are currently working on helping  groups to move to native hosting.  In most cases they will do the whole job for you, so if you need help to move your group, please drop them an email, they will be happy to help.

mentors@ilovefreegle.org

If you prefer to move your own group, they will send you a file telling you how to do that.

Please don't leave it to the last moment. If you want your Yahoo members  to stay with Freegle, move your group now.
   
We can't let this drag on, so on 1st October we'll pull the plug on the code that links Freegle Direct to Yahoo Groups.  After that:

- If you are on Freegle Direct and Yahoo, you will in effect be running two entirely different groups with different members and different posts. 
- If you are currently on Yahoo only, then along with all other Yahoo groups,  your group will no longer be a part of the Freegle organisation.

Please act now and ask the Mentor team to help move your group away from Yahoo Groups. 

(This is an automated mail - if you're already sorting this out, then that's fine!)
";

foreach ($groups as $group) {
    $g = new Group($dbhr, $dbhm, $group['id']);
    error_log("..." . $g->getPrivate('nameshort'));
    
    $mods = $g->getMods();
    
    $emails = [ 
        [ 
            'name' => $g->getName() . " Volunteers", 
            'email' => $g->getModsEmail()
        ]
    ];
    
    foreach ($mods as $mod) {
        $u = new User($dbhr, $dbhm, $mod);
        $emails[] = [
            'name' => $u->getName(),
            'email' => $u->getEmailPreferred()
        ];
    }
    
    error_log("..." . count($emails) . " addresses");
    
    foreach ($emails as $email) {
        $html = $twig->render('leavingyahoo.html', [
            'name' => $email['name'],
            'email' => $email['email']
        ]);

        $message = Swift_Message::newInstance()
            ->setSubject("It's time to move " . $g->getName() . " away from Yahoo")
            ->setFrom([ MENTORS_ADDR => 'Freegle' ])
            ->setTo($email['email'])
            ->setBody($text);

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        list ($transport, $mailer) = getMailer();
        $mailer->send($message);

        sleep(300);
    }
}

