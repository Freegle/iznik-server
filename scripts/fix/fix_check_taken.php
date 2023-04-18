<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

function canon($msg) {
    $msg = strtolower($msg);

    // Remove all but alphabetic characters
    $msg = preg_replace('/[^a-zA-Z]/', ' ', $msg);

    // Remove all whitespace
    $msg = preg_replace('/\s+/', '', $msg);

    return $msg;
}

$phrases = [
"Sorry taken",
"Sorry it’s been taken",
"sorry it has been taken",
"Taken",
"Sorry it's been taken",
"Sorry they have been taken",
"Sorry now taken",
"sorry already taken",
"Sorry this has now been taken",
"Sorry this has been taken",
"Sorry its been taken",
"Sorry it’s taken",
"Sorry, taken",
"Sorry, this has now been taken.",
"Sorry it's taken",
"Taken sorry",
"Sorry, it has been taken.",
"Sorry, it's been taken.",
"Sorry, taken.",
"Sorry its taken",
"Sorry it's been taken.",
"Sorry, it has been taken",
"NOW TAKEN",
"Sorry this has been taken.",
"Sorry, now taken",
"Sorry, it's been taken",
"Sorry it has been taken.",
"Sorry they are taken",
"Sorry these have been taken",
"This has now been taken",
"Sorry this has now been taken.",
"Sorry, already taken.",
"Sorry been taken",
"This has now been taken.",
"Sorry, they have been taken.",
"Sorry it’s been taken.",
"Sorry they have been taken.",
"Sorry, this has been taken",
"Sorry, this has been taken.",
"Sorry taken.",
"Sorry these have now been taken",
"Sorry, now taken.",
"Sorry taken now",
"Sorry, this has now been taken",
"Sorry, already taken",
"sorry they’ve been taken",
"already taken",
"Sorry, it’s been taken.",
"Sorry they've been taken",
"This has been taken",
"sorry, it’s been taken",
"Sorry already taken.",
"Sorry now taken.",
"It's been taken",
"Sorry its been taken.",
"Sorry this is taken",
"Sorry has been taken",
"It has been taken",
"Sorry, they have been taken",
"Sorry it is taken",
"Sorry these have now been taken.",
"Sorry just taken",
"Sorry it's taken.",
"Taken, sorry",
"Sorry it has now been taken",
"Sorry these have been taken.",
"Sorry, these have now been taken.",
"Sorry, its been taken.",
"Sorry, they've been taken.",
"Sorry. Taken",
"Sorry just been taken",
"Sorry this is now taken",
"These have now been taken",
"taken now",
"Sorry, these have been taken.",
"Sorry - taken",
"sORRY ALL TAKEN",
"sorry, it's taken",
"It’s been taken",
"Sorry, they've been taken",
"Sorry but this has now been taken",
"Sorry it  has been taken.",
"Sorry this has been taken now",
"I'm sorry they were taken but thank you for messaging",
"Sorry, these have been taken",
"Sorry it’s been taken but thank you for your interest",
"Sorry it’s gone",
"Sorry gone",
"sorry its gone",
"Sorry it's gone",
"Sorry it has gone",
"gone sorry",
"Sorry they have gone",
"Gone",
"Sorry now gone",
"Sorry, it's gone.",
"Sorry gone now",
"sorry this has gone",
"Sorry, it's gone",
"Sorry this has now gone",
"Has this gone",
"Sorry already gone",
"Sorry it’s gone.",
"Sorry it's gone.",
"Sorry, gone",
"Sorry it has gone.",
"Sorry all gone",
"Sorry it’s gone now",
"Sorry they’ve gone",
"Sorry they are gone",
"It’s gone sorry",
"Sorry, it’s gone",
"Sorry just gone",
"It's gone",
"Sorry they have gone.",
"Has this gone?",
"Sorry they've gone",
"Sorry this has now gone.",
"Gone now",
"It's gone sorry",
"It’s gone",
"Sorry these have gone",
"Sorry, it’s gone.",
"sorry its gone.",
"Sorry it's gone now",
"sorry its gone now",
"Sorry this has gone now",
"Sorry gone.",
"Sorry, it has gone",
"Sorry this has gone.",
"Sorry, it has gone.",
"Sorry these have now gone",
"Sorry it is gone",
"Its gone",
"Sorry, its gone",
"Sorry, gone.",
"Sorry, already gone.",
"gone, sorry",
"Sorry they have gone now",
"Gone now sorry",
"No sorry it’s gone",
"Sorry it has gone now",
"Sorry it’s now gone",
"Sorry it’s already gone",
"Sorry it has now gone",
"all gone",
"Sorry, this has now gone.",
"Sorry it’s just gone",
"This has now gone",
"Sorry, it's gone now",
"Sorry now gone.",
"Now gone",
"Sorry they have now gone",
"Sorry, already gone",
"Hi sorry it’s gone",
"So sorry it’s gone",
"Sorry, now gone",
"It’s gone I’m afraid",
"Sorry, they have gone.",
"sorry, this has now gone",
"sorry all gone now",
"I'm sorry it's gone",
"Sorry, these have now gone.",
"Sorry, They've gone.",
"Sorry these have now gone.",
"Sorry, it's already gone.",
"sorry gone now.",
"All gone sorry",
"Sorry they've gone.",
"Sorry, they've gone",
"Sorry these have gone now",
"Sorry it has already gone",
"Sorry, its gone.",
"Sorry - gone",
"Sorry already gone.",
"It's gone, sorry",
"Its gone sorry",
];

$canonPhrases = [];

foreach ($phrases as $p) {
    $canonPhrases[] = canon($p);
}

$canonPhrases = array_unique($canonPhrases);
error_log("Canon " . count($canonPhrases) . " vs " . count($phrases));

$poss = $dbhr->preQuery("SELECT * FROM chat_messages WHERE date >= '2023-01-01' AND (message LIKE '%taken%' OR message like '%gone%')");
$foundMessage = 0;
$missedMessage = 0;
$messageTaken = 0;
$messageNotTaken = 0;
$platform = 0;
$notPlatform = 0;

error_log("found " . count($poss));

foreach ($poss as $p) {
    $msg = $p['message'];
    $canon = canon($msg);

    if (in_array($canon, $canonPhrases)) {
        if ($p['platform']) {
            $platform++;
        } else {
            $notPlatform++;
        }

        $prev = $dbhr->preQuery("SELECT refmsgid FROM chat_messages WHERE chatid = ? AND date < ? AND DATEDIFF(?, date) < 31 ORDER BY date DESC LIMIT 1;", [
            $p['chatid'],
            $p['date'],
            $p['date']
        ]);

        if (count($prev)) {
            $foundMessage++;

            $m = new Message($dbhr, $dbhm, $prev[0]['refmsgid']);
            $outcome = $m->hasOutcome();

            if (!$outcome || $outcome == Message::OUTCOME_EXPIRED) {
                $messageNotTaken++;
            } else {
                $messageTaken++;
            }
        } else {
            $missedMessage++;
        }
    }
}

error_log("\n\nFound $foundMessage messages vs $missedMessage, $messageTaken taken vs $messageNotTaken not taken, $platform platform vs $notPlatform not platform");
