<?php
namespace Freegle\Iznik;

function donations() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $me = Session::whoAmI($dbhr, $dbhm);
    $groupid = (Utils::presint('groupid', $_REQUEST, NULL));

    switch ($_REQUEST['type']) {
        case 'GET': {
            # No permissions needed - this discloses a summary, not the details.
            $d = new Donations($dbhr, $dbhm, $groupid);
            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'donations' => $d->get()
            ];

            break;
        }

        case 'PUT': {
            $d = new Donations($dbhr, $dbhm, $groupid);
            $uid = Utils::presint('userid', $_REQUEST, NULL);
            $amount = Utils::presfloat('amount', $_REQUEST, 0);
            $date = Utils::presdef('date', $_REQUEST, NULL);

            $ret = [
                'ret' => 1,
                'status' => 'Permission denied or invalid parameters'
            ];

            if ($me && $me->hasPermission(User::PERM_GIFTAID) && $uid && $amount && $date) {
                $u = User::get($dbhr, $dbhm, $uid);

                $ret = [
                    'ret' => 2,
                    'status' => 'Invalid userid'
                ];

                if ($u->getId() == $uid) {
                    $id = $d->add($uid, $u->getEmailPreferred(), $u->getName(), $date, 'External added at ' . date("Y-m-d H:i:s", time()), $amount, Donations::TYPE_EXTERNAL, NULL);

                    $ret = [
                        'ret' => 3,
                        'status' => 'Add failed'
                    ];

                    if ($id) {
                        $giftaid = $d->getGiftAid($u->getId());

                        if (!$giftaid || $giftaid['period'] == Donations::PERIOD_THIS) {
                            # Ask them to complete a gift aid form.
                            $n = new Notifications($dbhr, $dbhm);
                            $n->add(NULL, $u->getId(), Notifications::TYPE_GIFTAID, NULL);
                        }

                        $text = $u->getName() ." (" . $u->getEmailPreferred() . ") donated £$amount.  Please can you thank them?";
                        $message = \Swift_Message::newInstance()
                            ->setSubject($text)
                            ->setFrom(NOREPLY_ADDR)
                            ->setTo(INFO_ADDR)
                            ->setCc('log@ehibbert.org.uk')
                            ->setBody($text);

                        list ($transport, $mailer) = Mail::getMailer();
                        Mail::addHeaders($message, Mail::DONATE_EXTERNAL);

                        $mailer->send($message);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'id' => $id
                        ];
                    }
                }
            }
        }
    }

    return($ret);
}
