<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Set up any missing postcodes and houses we can identify.
$d = new Donations($dbhr, $dbhm);
$count = $d->identifyGiftAidPostcode();
$count = $d->identifyGiftAidHouse();

# Mark any donations we can as having gift aid consent.
$d->identifyGiftAidedDonations();

# As of 2020-07-03 we can claim gift aid back to 2020-04-06.  Look for donations from PayPal Donate (which doesn't
# handle gift aid), where we don't have gift aid consent and where the donation was a couple of days ago (to give
# the initial ask time to work).
$start = date('Y-m-d', strtotime("48 hours ago"));
$donations = $dbhr->preQuery("SELECT users_donations.* FROM `users_donations` LEFT JOIN giftaid ON giftaid.userid = users_donations.userid WHERE users_donations.timestamp >= '2016-04-06' AND users_donations.timestamp <= '$start' AND source = 'DonateWithPaypal' AND giftaidconsent = 0 AND giftaid.userid IS NULL AND giftaidchaseup IS NULL AND users_donations.userid IS NOT NULL;");

$sentto = [];

foreach ($donations as $donation) {
    # Don't send duplicates, either from previous ones or within this cycle.
    if (!Utils::pres($donation['userid'], $sentto)) {
        $previous = $dbhr->preQuery("SELECT * FROM users_donations WHERE userid = ? and giftaidchaseup IS NOT NULL;", [
            $donation['userid']
        ]);

        if (count($previous)) {
            # Fix up previous bug.
            $dbhm->preExec("UPDATE users_donations SET giftaidchaseup = NOW() WHERE userid = ?;", [
                $donation['userid']
            ]);
        } else {
            # Never sent one.
            $sentto[$donation['userid']] = TRUE;

            # Add a notification onsite.
            $n = new Notifications($dbhr, $dbhm);
            $n->add(NULL, $donation['userid'], Notifications::TYPE_GIFTAID, NULL);

            # Mail them
            try {
                $u = new User($dbhr, $dbhm, $donation['userid']);
                $email = $u->getEmailPreferred();
                error_log("...$email");

                $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/donations');
                $twig = new \Twig_Environment($loader);
                list ($transport, $mailer) = Mail::getMailer();

                $date = date(' d-M-Y', strtotime($donation['timestamp']));

                $message = \Swift_Message::newInstance()
                    ->setSubject("Could Freegle collect Gift Aid on your donation?")
                    ->setFrom(PAYPAL_THANKS_FROM)
                    ->setReplyTo(PAYPAL_THANKS_FROM)
                    ->setTo($email)
                    ->setBody("You kindly donated to Freegle on $date.  We can make your kind donation go even further if we can claim Gift Aid.  If you can, please go to https://www.ilovefreegle.org/giftaid to complete a donation.");

                Mail::addHeaders($message, Mail::THANK_DONATION);

                $html = $twig->render('chaseup.html', [
                    'name' => $u->getName(),
                    'email' => $u->getEmailPreferred(),
                    'unsubscribe' => $u->loginLink(USER_SITE, $u->getId(), "/unsubscribe", NULL),
                    'date' => $date
                ]);

                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                # Outlook.
                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);

                Mail::addHeaders($message, Mail::THANK_DONATION, $u->getId());

                $mailer->send($message);

                # Record the ask, for all donations from this user.
                $dbhm->preExec("UPDATE users_donations SET giftaidchaseup = NOW() WHERE userid = ?;", [
                    $donation['userid']
                ]);
            } catch (\Exception $e) { error_log("Failed " . $e->getMessage()); };
        }
    }
}

Utils::unlockScript($lockh);