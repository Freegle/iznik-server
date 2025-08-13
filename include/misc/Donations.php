<?php
namespace Freegle\Iznik;



class Donations
{
    const PERIOD_THIS = 'This';
    const PERIOD_SINCE = 'Since';
    const PERIOD_FUTURE = 'Future';
    const PERIOD_DECLINED = 'Declined';
    const PERIOD_PAST_4_YEARS_AND_FUTURE = 'Past4YearsAndFuture';

    const TYPE_PAYPAL = 'PayPal';
    const TYPE_EXTERNAL = 'External';
    const TYPE_OTHER = 'Other';
    const TYPE_STRIPE = 'Stripe';

    const SOURCE_DONATE_WITH_PAYPAL = 'DonateWithPayPal';
    const SOURCE_PAYPAL_GIVING_FUND = 'PayPalGivingFund';
    const SOURCE_FACEBOOK = 'Facebook';
    const SOURCE_EBAY = 'eBay';
    const SOURCE_BANK_TRANSFER = 'BankTransfer';

    const MANUAL_THANKS = 20;

    public static function getExcludedPayersCondition($field = 'payer') {
        $excludeList = defined('DONATIONS_EXCLUDE') ? DONATIONS_EXCLUDE : 'ppgfukpay@paypalgivingfund.org';
        $excludeEmails = array_map('trim', explode(',', $excludeList));
        $conditions = array_map(function($email) use ($field) {
            return "$field != '$email'";
        }, $excludeEmails);
        return '(' . implode(' AND ', $conditions) . ')';
    }

    public static function isExcludedPayer($email) {
        $excludeList = defined('DONATIONS_EXCLUDE') ? DONATIONS_EXCLUDE : 'ppgfukpay@paypalgivingfund.org';
        $excludeEmails = array_map('trim', explode(',', $excludeList));
        return in_array($email, $excludeEmails);
    }

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $groupid = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->groupid = $groupid;
    }

    public function add($eid, $email, $name, $date, $txnid, $gross, $donationType, $transactionType = NULL, $source = Donations::SOURCE_DONATE_WITH_PAYPAL) {
        $this->dbhm->preExec("INSERT INTO users_donations (userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount, TransactionType, `type`, `source`) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE userid = ?, timestamp = ?;", [
            $eid,
            $email,
            $name,
            $date,
            $txnid,
            $gross,
            $transactionType,
            $donationType,
            $source,
            $eid,
            $date
        ]);

        return $this->dbhm->lastInsertId();
    }

    public function delete($id) {
        $this->dbhm->preExec("DELETE FROM users_donations WHERE id = ?;", [
            $id
        ]);
    }

    public function get() {
        $target = $this->groupid ? $this->dbhr->preQuery("SELECT fundingtarget FROM `groups` WHERE id = {$this->groupid};")[0]['fundingtarget'] : DONATION_TARGET;
        $ret = [
            'target' => $target
        ];

        $mysqltime = date("Y-m-d", strtotime('first day of this month'));
        $groupq = $this->groupid ? " INNER JOIN memberships ON users_donations.userid = memberships.userid AND groupid = {$this->groupid} " : '';

        $excludeCondition = self::getExcludedPayersCondition('payer');
        $totals = $this->dbhr->preQuery("SELECT SUM(GrossAmount) AS raised FROM users_donations $groupq WHERE timestamp >= ? AND $excludeCondition;", [
            $mysqltime
        ]);
        $ret['raised'] = $totals[0]['raised'];
        return($ret);
    }

    public function recordAsk($userid) {
        $this->dbhm->preExec("INSERT INTO users_donations_asks (userid) VALUES (?);", [ $userid ]);
    }

    public function lastAsk($userid) {
        $ret = NULL;

        $asks = $this->dbhr->preQuery("SELECT MAX(timestamp) AS max FROM users_donations_asks WHERE userid = ?;", [
            $userid
        ]);

        foreach ($asks as $ask) {
            $ret = $ask['max'];
        }

        return($ret);
    }

    public function getGiftAid($userid) {
        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE userid = ? AND deleted IS NULL;", [
            $userid
        ]);

        foreach ($giftaids as &$giftaid) {
            $giftaid['timestamp'] = Utils::ISODate($giftaid['timestamp']);
        }

        return count($giftaids) ? $giftaids[0] : NULL;
    }

    public function searchGiftAid($search) {
        $q = $this->dbhr->quote("%$search%");
        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE fullname LIKE $q OR homeaddress LIKE $q OR id LIKE $q;");

        foreach ($giftaids as &$giftaid) {
            $giftaid['timestamp'] = Utils::ISODate($giftaid['timestamp']);
            $u = User::get($this->dbhr, $this->dbhm, $giftaid['userid']);
            $giftaid['email'] = $u->getEmailPreferred();
            $giftaid['donations'] = $this->listByUser($giftaid['userid']);
        }

        return $giftaids;
    }

    public function listGiftAidReview() {
        $giftaids = $this->dbhr->preQuery("SELECT giftaid.*, SUM(users_donations.GrossAmount) AS donations FROM giftaid 
                                                LEFT JOIN users_donations ON users_donations.userid = giftaid.userid
                                                WHERE reviewed IS NULL AND deleted IS NULL AND period != ? GROUP BY giftaid.userid ORDER BY timestamp DESC;", [
            Donations::PERIOD_DECLINED
        ], FALSE, FALSE);

        $uids = array_filter(array_column($giftaids, 'userid'));
        $u = new User($this->dbhr, $this->dbhm);
        $emails = $u->getEmailsById($uids);

        foreach ($giftaids as &$giftaid) {
            $giftaid['timestamp'] = Utils::ISODate($giftaid['timestamp']);
            $giftaid['email'] = Utils::presdef($giftaid['userid'], $emails, NULL);
        }

        return $giftaids;

    }
    public function countGiftAidReview() {
        $giftaids = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM giftaid WHERE reviewed IS NULL AND deleted IS NULL AND period != ? ORDER BY timestamp ASC;", [
            Donations::PERIOD_DECLINED
        ], FALSE, FALSE);
        return $giftaids[0]['count'];
    }

    public function editGiftAid($id, $period, $fullname, $homeaddress, $postcode, $housenameornumber, $reviewed, $deleted) {
        if ($period) {
            $this->dbhm->preExec("UPDATE giftaid SET period = ? WHERE id = ?;", [
                $period,
                $id
            ]);
        }

        if ($fullname) {
            $this->dbhm->preExec("UPDATE giftaid SET fullname = ? WHERE id = ?;", [
                $fullname,
                $id
            ]);
        }

        if ($homeaddress) {
            $this->dbhm->preExec("UPDATE giftaid SET homeaddress = ? WHERE id = ?;", [
                $homeaddress,
                $id
            ]);
        }

        if ($postcode) {
            $this->dbhm->preExec("UPDATE giftaid SET postcode = ? WHERE id = ?;", [
                $postcode,
                $id
            ]);
        }

        if ($housenameornumber) {
            $this->dbhm->preExec("UPDATE giftaid SET housenameornumber = ? WHERE id = ?;", [
                $housenameornumber,
                $id
            ]);
        }

        if ($reviewed) {
            $this->dbhm->preExec("UPDATE giftaid SET reviewed = NOW() WHERE id = ?;", [
                $id
            ]);
        }

        if ($deleted) {
            $this->dbhm->preExec("UPDATE giftaid SET deleted = NOW() WHERE id = ?;", [
                $id
            ]);
        }
    }

    public function setGiftAid($userid, $period, $fullname, $homeaddress) {
        $this->dbhm->preExec("INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), period = ?, fullname = ?, homeaddress = ?, deleted = NULL;", [
            $userid,
            $period,
            $fullname,
            $homeaddress,
            $period,
            $fullname,
            $homeaddress
        ]);

        return $this->dbhm->lastInsertId();
    }

    public function deleteGiftAid($userid) {
        // They may or may not already exist in the table.
        $u = User::get($this->dbhr, $this->dbhm, $userid);

        $this->dbhm->preExec("INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE period = ?, deleted = NOW()", [
            $userid,
            'Declined',
            $u->getName(),
            '',
            'Declined'
        ]);
    }

    public function correctUserIdInDonations() {
        # Look first for donations not associated with a user at the time of donation, but where we now have a user.
        # This handles cases like someone making a donation, leaving and rejoining.  It can result in a small amount
        # of extra gift aid.
        #
        # Previously we did this only for donations with a gift aid record, but doing it for any we can match
        # will result in better records of donations, which helps Support.
        $missing = $this->dbhr->preQuery("SELECT users_emails.userid AS emailid, users_donations.id AS donationid FROM users_donations 
    INNER JOIN users_emails ON users_emails.email = users_donations.Payer 
         WHERE users_donations.userid IS NULL;
");
        foreach ($missing as $m) {
            $this->dbhm->preExec("UPDATE users_donations SET userid = ? WHERE id = ?;", [
                $m['emailid'],
                $m['donationid']
            ]);
        }
    }
    
    public function identifyGiftAidedDonations($id = NULl) {
        $idq = $id ? " AND id = $id " : '';
        $found = 0;

        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE reviewed IS NOT NULL $idq;");

        $this->correctUserIdInDonations();
        
        foreach ($giftaids as $giftaid) {
            switch ($giftaid['period']) {
                case Donations::PERIOD_PAST_4_YEARS_AND_FUTURE:
                    $mysqltime = date("Y-m-d", strtotime("4 years ago"));
                    $this->dbhm->preExec("UPDATE users_donations SET giftaidconsent = 1 WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= ?;", [
                        $giftaid['userid'],
                        $mysqltime
                    ]);

                    $found += $this->dbhm->rowsAffected();
                    break;
                case Donations::PERIOD_SINCE: {
                    # Earliest we can claim is 6th April 2016.
                    $this->dbhm->preExec("UPDATE users_donations SET giftaidconsent = 1 WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= '2016-04-06';", [
                        $giftaid['userid']
                    ]);

                    $found += $this->dbhm->rowsAffected();
                    break;
                }

                case Donations::PERIOD_THIS: {
                    # Only donations on the same day.
                    $mysqltime = date("Y-m-d", strtotime($giftaid['timestamp']));
                    $this->dbhm->preExec("UPDATE users_donations SET giftaidconsent = 1 WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= '2016-04-06' AND date(timestamp) = ?;", [
                        $giftaid['userid'],
                        $mysqltime
                    ]);

                    $found += $this->dbhm->rowsAffected();
                    break;
                }

                case Donations::PERIOD_FUTURE: {
                    # Only donations on or after the day of consent.
                    $mysqltime = date("Y-m-d", strtotime($giftaid['timestamp']));
                    $this->dbhm->preExec("UPDATE users_donations SET giftaidconsent = 1 WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= '2016-04-06' AND date(timestamp) >= ?;", [
                        $giftaid['userid'],
                        $mysqltime
                    ]);

                    $found += $this->dbhm->rowsAffected();
                    break;
                }

                case Donations::PERIOD_DECLINED: {
                    # Declined to give gift aid - nothing to do.
                    break;
                }
            }
        }

        return $found;
    }

    public function identifyGiftAidPostcode($id = NULL) {
        $idq = $id ? " AND id = $id " : '';
        $found = 0;

        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE postcode IS NULL AND deleted IS NULL $idq;");
        $a = new Address($this->dbhr, $this->dbhm);

        foreach ($giftaids as $giftaid) {
            $addresses = $a->listForUser($giftaid['userid']);
            $possible = NULL;

            foreach ($addresses as $address) {
                $pc = $address['postcode']['name'];

                if (stripos($giftaid['homeaddress'], $pc) !== FALSE) {
                    # We've found the postcode in one of their addresses, so we can record it.
                    $possible = $pc;
                    break;
                }
            }

            if (!$possible) {
                # We didn't find it in one of their addresses.  See if we can find a postcode using the government
                # regex;
                if (preg_match(Utils::POSTCODE_PATTERN, $giftaid['homeaddress'], $matches)) {
                    $possible = strtoupper($matches[0]);
                }
            }

            if ($possible) {
                $l = new Location($this->dbhr, $this->dbhm);
                $locs = $l->typeahead($possible);

                if (count($locs)) {
                    $found++;
                    $this->dbhm->preExec("UPDATE giftaid SET postcode = ? WHERE id = ?;", [
                        $locs[0]['name'],
                        $giftaid['id']
                    ]);
                }
            }
        }

        return $found;
    }

    public function identifyGiftAidHouse($id = NULL) {
        $idq = $id ? " AND id = $id " : '';
        $found = 0;

        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE housenameornumber IS NULL AND deleted IS NULL $idq;");

        foreach ($giftaids as $giftaid) {
            # Look for a house number, possibly with a letter, e.g. 13a.
            #error_log("Check {$giftaid['homeaddress']}");
            if (preg_match('/^([\d\/\\-]+[a-z]{0,1})[\w\s]/im', $giftaid['homeaddress'], $matches)) {
                $number = trim($matches[0]);
                #error_log("Found $number " . var_export($matches, TRUE));

                $this->dbhm->preExec("UPDATE giftaid SET housenameornumber = ? WHERE id = ?;", [
                    $number,
                    $giftaid['id']
                ]);

                $found++;
            }
        }

        return $found;
    }

    public function listByUser($userid) {
        $donations = $this->dbhr->preQuery("SELECT * FROM users_donations WHERE userid = ? ORDER BY id DESC;", [
            $userid
        ]);

        foreach ($donations as &$donation) {
            $donation['timestamp'] = Utils::ISODate($donation['timestamp']);
        }

        return $donations;
    }

    public function sendBirthdayEmails($emailOverride = null, $groupids = null) {
        # Find groups founded on this date in any year
        $today = date('m-d'); // Current month-day format
        error_log("Looking for groups founded on $today");

        # Build the query with optional group ID filtering
        $groupIdFilter = '';
        $params = [$today, Group::GROUP_FREEGLE];
        
        if ($groupids !== null) {
            if (is_array($groupids)) {
                $placeholders = str_repeat('?,', count($groupids) - 1) . '?';
                $groupIdFilter = " AND id IN ($placeholders)";
                $params = array_merge($params, $groupids);
            } else {
                $groupIdFilter = " AND id = ?";
                $params[] = $groupids;
            }
        }
        
        $groups = $this->dbhr->preQuery("SELECT id, nameshort, namefull, founded, YEAR(NOW()) - YEAR(founded) AS age 
                                      FROM `groups` 
                                      WHERE DATE_FORMAT(founded, '%m-%d') = ? 
                                      AND type = ? 
                                      AND publish = 1 
                                      AND onmap = 1
                                      AND YEAR(NOW()) - YEAR(founded) > 0
                                      $groupIdFilter
                                      ORDER BY age DESC", $params);

        $count = 0;

        foreach ($groups as $group) {
            error_log("Processing group {$group['nameshort']} - {$group['age']} years old");
            
            # Get group members who are allowed to receive emails
            # Basic query to get active group members with marketing consent - we'll check email permissions with sendOurMails
            $members = $this->dbhr->preQuery("SELECT DISTINCT users.id, users.fullname, users.firstname 
                                           FROM users 
                                           INNER JOIN memberships ON users.id = memberships.userid 
                                           WHERE memberships.groupid = ? 
                                           AND users.deleted IS NULL 
                                           AND users.marketingconsent = 1
                                           AND memberships.collection = ?", [
                $group['id'],
                MembershipCollection::APPROVED
            ]);
            
            foreach ($members as $member) {
                $u = new User($this->dbhr, $this->dbhm, $member['id']);
                $email = $emailOverride ? $emailOverride : $u->getEmailPreferred();
                
                # Check if we can send emails to this user using sendOurMails (skip check if overriding email)
                if (!$email || (!$emailOverride && !$u->sendOurMails())) {
                    continue;
                }
                
                # Check if we've sent a birthday appeal to this user recently (skip check if overriding email)
                $canSendBirthdayAppeal = TRUE;
                $days_since = NULL;

                if (!$emailOverride) {
                    $settings = $u->getPrivate('settings');
                    $settings = $settings ? json_decode($settings, TRUE) : [];
                    $lastBirthdayAppeal = Utils::presdef('lastbirthdayappeal', $settings, null);
                    
                    if ($lastBirthdayAppeal && time() - strtotime($lastBirthdayAppeal) < 31 * 24 * 60 * 60) {
                        $canSendBirthdayAppeal = FALSE;
                        $days_since = floor((time() - strtotime($lastBirthdayAppeal)) / (24 * 60 * 60));
                        error_log("Skipping {$member['id']} - sent birthday appeal $days_since days ago");
                    }
                }
                
                if ($canSendBirthdayAppeal) {
                    try {
                        error_log("Sending birthday email to {$member['id']} " . $u->getName() . " at $email for group {$group['nameshort']}");
                        
                        # Use Twig to render the HTML template
                        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
                        $twig = new \Twig_Environment($loader);
                        
                        # Fetch active volunteers for this group
                        $volunteers = [];
                        $g = Group::get($this->dbhr, $this->dbhm, $group['id']);
                        
                        # Get moderators using the same logic as the API
                        $ctx = NULL;
                        $mods = $g->getMembers(100, NULL, $ctx, NULL, MembershipCollection::APPROVED, NULL, NULL, NULL, NULL, Group::FILTER_MODERATORS);
                        
                        if ($mods) {
                            $oneYearAgo = date('Y-m-d H:i:s', strtotime('-1 year'));
                            foreach ($mods as $mod) {
                                $modUser = new User($this->dbhr, $this->dbhm, $mod['userid']);
                                $lastAccess = $modUser->getPrivate('lastaccess');
                                
                                # Only include moderators who are active (accessed within last year) and have publish consent
                                if ($lastAccess && $lastAccess > $oneYearAgo && $modUser->getPrivate('publishconsent')) {
                                    $displayName = $modUser->getName();
                                    $firstName = explode(' ', $displayName)[0];
                                    $volunteers[] = [
                                        'id' => $mod['userid'],
                                        'displayname' => $displayName,
                                        'firstname' => $firstName
                                    ];
                                }
                            }
                        }

                        $html = $twig->render('birthday.html', [
                            'groupname' => $group['namefull'],
                            'groupnameshort' => $group['nameshort'],
                            'groupage' => $group['age'],
                            'groupid' => $group['id'],
                            'email' => $email,
                            'volunteers' => $volunteers
                        ]);
                        
                        # Set sender info - use group name and moderator email from getModsEmail()
                        $fromEmail = $g->getModsEmail();
                        $fromName = $group['namefull'];

                        # Create the email
                        list ($transport, $mailer) = Mail::getMailer();
                        $m = \Swift_Message::newInstance()
                            ->setSubject("Happy Birthday to all us freeglers!")
                            ->setFrom([$fromEmail => $fromName])
                            ->setReplyTo($fromEmail)
                            ->setTo($email)
                            ->setBody("Happy Birthday to " . $group['namefull'] . "!\n\n" . 
                                     $group['namefull'] . " is " . $group['age'] . " years old today!\n\n" .
                                     "Together, we've been saving money, time and the planet for another year.\n\n" .
                                     "Free to use, but not free to run - help us keep " . $group['namefull'] . " thriving for another year.\n\n" .
                                     "Donate at: https://www.ilovefreegle.org/donate?groupid=" . $group['id'] . "\n\n" .
                                     "Thanks for freegling!");

                        # Add HTML version
                        $htmlPart = \Swift_MimePart::newInstance();
                        $htmlPart->setCharset('utf-8');
                        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                        $htmlPart->setContentType('text/html');
                        $htmlPart->setBody($html);
                        $m->attach($htmlPart);

                        Mail::addHeaders($this->dbhr, $this->dbhm, $m, Mail::ASK_DONATION, $u->getId());

                        $mailer->send($m);
                        $count++;
                        
                        # Record that we've sent a birthday appeal to this user (skip if overriding email)
                        if (!$emailOverride) {
                            $settings = $u->getPrivate('settings');
                            $settings = $settings ? json_decode($settings, TRUE) : [];
                            $settings['lastbirthdayappeal'] = date('Y-m-d H:i:s');
                            $u->setPrivate('settings', json_encode($settings));
                        }
                        
                        # If using email override, stop after sending one email
                        if ($emailOverride) {
                            error_log("Email override used - stopping after one email");
                            return $count;
                        }
                        
                    } catch (\Exception $e) {
                        \Sentry\captureException($e);
                        error_log("Failed to send birthday email to {$member['id']}: " . $e->getMessage());
                    }
                } else {
                    # User was asked recently, skip
                    error_log("Skipping {$member['id']} - asked for donation $days_since days ago");
                }
            }
        }

        error_log("Sent $count birthday emails");
        return $count;
    }
}