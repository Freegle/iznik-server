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

            // We don't get detailed reporting from JustGiving, so allow recording of  a zero-value donation here.  This will trigger
            // a Supporter button.  Obviously this would also allow non-donors to get the button if they were savvy enough,
            // but if you're looking at this code and thinking about that, just ask yourself what your mother would want you
            // to do.
            if ($me && ($me->hasPermission(User::PERM_GIFTAID) || !$amount) && $uid && $date) {
                $u = User::get($dbhr, $dbhm, $uid);

                $ret = [
                    'ret' => 2,
                    'status' => 'Invalid userid'
                ];

                if ($u->getId() == $uid) {
                    $id = $d->add($uid, $u->getEmailPreferred(), $u->getName(), $date, 'External for #' . $uid . ' added at ' . date("Y-m-d H:i:s", time()) . Donations::SOURCE_BANK_TRANSFER, $amount, Donations::TYPE_EXTERNAL, NULL, Donations::SOURCE_BANK_TRANSFER);

                    $ret = [
                        'ret' => 3,
                        'status' => 'Add failed'
                    ];

                    if ($id) {
                        if ($amount) {
                            $giftaid = $d->getGiftAid($u->getId());

                            if (!$giftaid || $giftaid['period'] == Donations::PERIOD_THIS) {
                                # Ask them to complete a gift aid form.
                                $n = new Notifications($dbhr, $dbhm);
                                $n->add(NULL, $u->getId(), Notifications::TYPE_GIFTAID, NULL);
                            }

                            $text = $u->getName() ." (" . $u->getEmailPreferred() . ") donated £$amount via an external donation.  Please can you thank them?";
                            $message = \Swift_Message::newInstance()
                                ->setSubject($text)
                                ->setFrom(NOREPLY_ADDR)
                                ->setTo(INFO_ADDR)
                                ->setCc('log@ehibbert.org.uk')
                                ->setBody($text);

                            list ($transport, $mailer) = Mail::getMailer();
                            Mail::addHeaders($dbhr, $dbhm, $message, Mail::DONATE_EXTERNAL);

                            $mailer->send($message);
                        }

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
