<?php
# Fix misattributed Stripe donations and find missing donations.
#
# Background: user 2893696 has an empty-string email record in users_emails.
# The correctUserIdInDonations() method matches donations with empty Payer to
# this user via '' = ''. This script:
#
# 1. Parses /var/www/stripeipn.out to extract billing_details from charge events
# 2. For charges not found in the log, falls back to Stripe API
# 3. Matches billing email to users_emails to find the real donor
# 4. When no email match, uses fuzzy name + activity + postcode matching
# 5. Reports and (with --commit) fixes the misattributed donations
# 6. Lists Stripe charges to find any that were never recorded
#
# Usage:
#   php fix_stripe_donations.php                  # Dry run - report only
#   php fix_stripe_donations.php --commit         # Actually update the database
#   php fix_stripe_donations.php --no-stripe-api  # Skip Stripe API fallback
#   php fix_stripe_donations.php --find-missing   # Also scan Stripe for missed donations

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$commit = in_array('--commit', $argv);
$useStripeApi = !in_array('--no-stripe-api', $argv);
$findMissing = in_array('--find-missing', $argv);

$BAD_USERID = 2893696;
$IPN_LOG = '/var/www/stripeipn.out';

# Titles to strip from names for matching
$TITLES = ['mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'sir', 'lady', 'rev', 'professor', 'doctor'];

#
# Helper: Escape and format a value for CSV output.
#
function csvField($val) {
    if ($val === NULL) {
        $val = '';
    }
    $val = (string)$val;
    # Always quote, escape internal quotes by doubling them.
    return '"' . str_replace('"', '""', $val) . '"';
}

function csvRow($fields) {
    echo implode(',', array_map(function($v) { return csvField($v); }, $fields)) . "\n";
}

error_log("=== Stripe Donation Fix-Up ===");
error_log("Mode: " . ($commit ? "COMMIT (will update database)" : "DRY RUN (report only)"));
error_log("");

#
# Helper: Normalize a name for fuzzy matching.
# Strips titles, lowercases, trims whitespace.
# Returns ['full' => 'liz dixon', 'first' => 'liz', 'last' => 'dixon', 'parts' => ['liz', 'dixon']]
#
function normalizeName($name) {
    global $TITLES;

    if (!$name) {
        return NULL;
    }

    $name = mb_strtolower(trim($name));

    # Remove titles
    $parts = preg_split('/\s+/', $name);
    $parts = array_values(array_filter($parts, function($p) use ($TITLES) {
        return !in_array(rtrim($p, '.'), $TITLES);
    }));

    if (count($parts) == 0) {
        return NULL;
    }

    return [
        'full' => implode(' ', $parts),
        'first' => $parts[0],
        'last' => count($parts) > 1 ? end($parts) : NULL,
        'parts' => $parts,
    ];
}

#
# Helper: Score how well two names match.
# Returns 0 (no match) to 1.0 (exact match).
#
function nameMatchScore($stripeName, $dbName) {
    $a = normalizeName($stripeName);
    $b = normalizeName($dbName);

    if (!$a || !$b) {
        return 0;
    }

    # Exact match after normalization
    if ($a['full'] === $b['full']) {
        return 1.0;
    }

    # Last name must match (or be very close)
    if (!$a['last'] || !$b['last']) {
        return 0;
    }

    $lastSimilarity = 0;
    similar_text($a['last'], $b['last'], $lastSimilarity);

    if ($lastSimilarity < 85) {
        return 0;
    }

    # First name matching — handle initials, short forms
    $firstScore = 0;

    if ($a['first'] === $b['first']) {
        $firstScore = 1.0;
    } elseif (strlen($a['first']) == 1 && $b['first'][0] === $a['first'][0]) {
        # "L" matches "Liz"
        $firstScore = 0.6;
    } elseif (strlen($b['first']) == 1 && $a['first'][0] === $b['first'][0]) {
        # "Liz" matches "L"
        $firstScore = 0.6;
    } elseif (strpos($b['first'], $a['first']) === 0 || strpos($a['first'], $b['first']) === 0) {
        # "Liz" starts with "Li" or "Elizabeth" starts with "Eliz"
        $firstScore = 0.7;
    } else {
        # Levenshtein for typos — allow small distance relative to length
        $lev = levenshtein($a['first'], $b['first']);
        $maxLen = max(strlen($a['first']), strlen($b['first']));

        if ($maxLen > 0 && ($lev / $maxLen) <= 0.3) {
            $firstScore = 0.5;
        }
    }

    if ($firstScore == 0) {
        return 0;
    }

    return ($lastSimilarity / 100) * 0.5 + $firstScore * 0.5;
}

#
# Helper: Get full Stripe charge details (billing name, email, postcode).
# Tries log first, then Stripe API.
#
function getChargeDetails($txnId, $logMap, $useStripeApi) {
    $details = [
        'billing_email' => NULL,
        'billing_name' => NULL,
        'billing_postcode' => NULL,
        'receipt_email' => NULL,
        'customer' => NULL,
        'metadata_uid' => NULL,
        'payment_intent' => NULL,
        'source' => NULL,
    ];

    if (isset($logMap[$txnId])) {
        $info = $logMap[$txnId];
        $details = array_merge($details, $info);
        $details['source'] = 'log';
    } elseif ($useStripeApi && $txnId) {
        try {
            $charge = \Stripe\Charge::retrieve($txnId);
            $details['billing_email'] = $charge->billing_details->email ?? NULL;
            $details['billing_name'] = $charge->billing_details->name ?? NULL;
            $details['billing_postcode'] = $charge->billing_details->address->postal_code ?? NULL;
            $details['receipt_email'] = $charge->receipt_email ?? NULL;
            $details['customer'] = $charge->customer ?? NULL;
            $details['metadata_uid'] = $charge->metadata->uid ?? NULL;
            $details['payment_intent'] = $charge->payment_intent ?? NULL;
            $details['source'] = 'stripe_api';
        } catch (\Exception $e) {
            $details['source'] = 'error';
            $details['error'] = $e->getMessage();
        }
    }

    # Resolve email from receipt_email fallback
    if (!$details['billing_email'] && $details['receipt_email']) {
        $details['billing_email'] = $details['receipt_email'];
    }

    return $details;
}

#
# Helper: Try to resolve user from Stripe customer
#
function resolveFromCustomer($customerId, $dbhr, $BAD_USERID) {
    if (!$customerId) {
        return NULL;
    }

    try {
        $customer = \Stripe\Customer::retrieve($customerId);

        # Try customer metadata uid
        $uid = $customer->metadata->uid ?? NULL;
        if ($uid && intval($uid) != $BAD_USERID) {
            return ['userid' => intval($uid), 'method' => 'customer_uid'];
        }

        # Try customer email
        $email = $customer->email ?? NULL;
        if ($email) {
            $users = $dbhr->preQuery("SELECT userid FROM users_emails WHERE email = ? AND userid != ?", [$email, $BAD_USERID]);
            if (count($users) > 0) {
                return ['userid' => $users[0]['userid'], 'method' => 'customer_email'];
            }
        }
    } catch (\Exception $e) {
        # Customer lookup failed
    }

    return NULL;
}

#
# Helper: Try to resolve user from PaymentIntent metadata
#
function resolveFromPaymentIntent($piId, $dbhr, $dbhm, $useStripeApi, $BAD_USERID) {
    if (!$piId || !$useStripeApi) {
        return NULL;
    }

    try {
        $pi = \Stripe\PaymentIntent::retrieve($piId);
        $uid = $pi->metadata->uid ?? NULL;

        if ($uid && intval($uid) != $BAD_USERID) {
            $u = User::get($dbhr, $dbhm, intval($uid));
            if ($u->getId() == intval($uid)) {
                return ['userid' => intval($uid), 'method' => 'pi_metadata'];
            }
        }

        # PI receipt_email as a bonus
        if ($pi->receipt_email) {
            $users = $dbhr->preQuery("SELECT userid FROM users_emails WHERE email = ? AND userid != ?", [$pi->receipt_email, $BAD_USERID]);
            if (count($users) > 0) {
                return ['userid' => $users[0]['userid'], 'method' => 'pi_email'];
            }
        }
    } catch (\Exception $e) {
        # PI lookup failed
    }

    return NULL;
}

#
# Helper: Fuzzy match a name + postcode + donation timestamp to find a likely user.
# Returns ['userid' => X, 'score' => Y, 'reasons' => [...]] or NULL.
#
function fuzzyMatchUser($billingName, $billingPostcode, $donationTimestamp, $dbhr, $BAD_USERID) {
    if (!$billingName) {
        return NULL;
    }

    $normalized = normalizeName($billingName);
    if (!$normalized || !$normalized['last']) {
        return NULL;
    }

    # Window: users active within 30 days either side of the donation
    $donationTime = strtotime($donationTimestamp);
    $windowStart = date('Y-m-d H:i:s', $donationTime - 30 * 86400);
    $windowEnd = date('Y-m-d H:i:s', $donationTime + 30 * 86400);

    # Find candidate users by last name (case insensitive), active in window.
    # Use multiple activity signals: lastaccess, messages, chat messages.
    $candidates = $dbhr->preQuery("
        SELECT DISTINCT u.id, u.fullname, u.firstname, u.lastname, u.lastaccess, u.lastlocation
        FROM users u
        WHERE u.id != ?
          AND u.deleted IS NULL
          AND u.fullname IS NOT NULL
          AND LOWER(u.fullname) LIKE ?
          AND (
              u.lastaccess BETWEEN ? AND ?
              OR EXISTS (SELECT 1 FROM messages m WHERE m.fromuser = u.id AND m.arrival BETWEEN ? AND ?)
              OR EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.userid = u.id AND cm.date BETWEEN ? AND ?)
          )
        LIMIT 200
    ", [
        $BAD_USERID,
        '%' . $normalized['last'] . '%',
        $windowStart, $windowEnd,
        $windowStart, $windowEnd,
        $windowStart, $windowEnd,
    ]);

    if (count($candidates) == 0) {
        return NULL;
    }

    $bestMatch = NULL;
    $bestScore = 0;

    foreach ($candidates as $candidate) {
        $reasons = [];
        $score = 0;

        # Name similarity (0 to 1.0)
        $nameScore = nameMatchScore($billingName, $candidate['fullname']);
        if ($nameScore == 0) {
            continue;
        }
        $score += $nameScore * 50;  # Max 50 points for name
        $reasons[] = sprintf("name=%.0f%%", $nameScore * 100);

        # Activity proximity — how close was their lastaccess to the donation?
        if ($candidate['lastaccess']) {
            $accessTime = strtotime($candidate['lastaccess']);
            $daysDiff = abs($donationTime - $accessTime) / 86400;

            if ($daysDiff <= 1) {
                $score += 20;
                $reasons[] = "active_same_day";
            } elseif ($daysDiff <= 7) {
                $score += 15;
                $reasons[] = "active_same_week";
            } elseif ($daysDiff <= 30) {
                $score += 10;
                $reasons[] = "active_same_month";
            }
        }

        # Location match — try postcode first, then group membership area.
        if ($billingPostcode) {
            $postcodeMatched = FALSE;

            # Direct postcode comparison against user's lastlocation
            if ($candidate['lastlocation']) {
                $locPostcode = $dbhr->preQuery("
                    SELECT l.name FROM locations l
                    INNER JOIN locations pc ON l.postcodeid = pc.id
                    WHERE l.id = ?
                ", [$candidate['lastlocation']]);

                if (count($locPostcode) > 0) {
                    $userPostcode = strtoupper(preg_replace('/\s+/', '', $locPostcode[0]['name']));
                    $stripePostcode = strtoupper(preg_replace('/\s+/', '', $billingPostcode));

                    if ($userPostcode === $stripePostcode) {
                        $score += 25;
                        $reasons[] = "postcode_exact";
                        $postcodeMatched = TRUE;
                    } else {
                        $userOutward = preg_replace('/\d[A-Z]{2}$/', '', $userPostcode);
                        $stripeOutward = preg_replace('/\d[A-Z]{2}$/', '', $stripePostcode);

                        if ($userOutward === $stripeOutward && strlen($userOutward) >= 2) {
                            $score += 15;
                            $reasons[] = "postcode_area";
                            $postcodeMatched = TRUE;
                        }
                    }
                }
            }

            # Group membership area: is the Stripe postcode near any of the user's groups?
            # Look up the postcode lat/lng, then check group membership proximity.
            if (!$postcodeMatched) {
                $pcNorm = strtoupper(trim($billingPostcode));
                $pcLoc = $dbhr->preQuery("
                    SELECT lat, lng FROM locations
                    WHERE type = 'Postcode' AND REPLACE(name, ' ', '') = REPLACE(?, ' ', '')
                    LIMIT 1
                ", [$pcNorm]);

                if (count($pcLoc) > 0) {
                    $pcLat = $pcLoc[0]['lat'];
                    $pcLng = $pcLoc[0]['lng'];

                    # Find groups this user is a member of, check distance to postcode.
                    # 30 miles ~ 48km is a reasonable radius for Freegle groups.
                    $nearbyGroups = $dbhr->preQuery("
                        SELECT g.nameshort,
                               (6371 * acos(cos(radians(?)) * cos(radians(g.lat)) * cos(radians(g.lng) - radians(?)) + sin(radians(?)) * sin(radians(g.lat)))) AS distance_km
                        FROM memberships m
                        INNER JOIN `groups` g ON m.groupid = g.id
                        WHERE m.userid = ? AND g.lat IS NOT NULL
                        HAVING distance_km < 48
                        ORDER BY distance_km ASC
                        LIMIT 1
                    ", [$pcLat, $pcLng, $pcLat, $candidate['id']]);

                    if (count($nearbyGroups) > 0) {
                        $distKm = round($nearbyGroups[0]['distance_km']);
                        $groupName = $nearbyGroups[0]['nameshort'];

                        if ($distKm < 16) {
                            # Within ~10 miles — strong location signal
                            $score += 20;
                            $reasons[] = "group_area({$groupName},{$distKm}km)";
                        } else {
                            # Within 30 miles — weaker but still meaningful
                            $score += 10;
                            $reasons[] = "group_nearby({$groupName},{$distKm}km)";
                        }
                    }
                }
            }
        }

        # Has this user donated before? Strong signal.
        $prevDonations = $dbhr->preQuery("SELECT COUNT(*) AS cnt FROM users_donations WHERE userid = ? AND id != 0", [
            $candidate['id']
        ]);
        if ($prevDonations[0]['cnt'] > 0) {
            $score += 10;
            $reasons[] = "prev_donor(" . $prevDonations[0]['cnt'] . ")";
        }

        # Gift aid declaration — if the candidate has one, they're a known donor.
        # If the name on the declaration matches the billing name, even stronger.
        $giftaid = $dbhr->preQuery("SELECT fullname FROM giftaid WHERE userid = ? AND deleted IS NULL", [
            $candidate['id']
        ]);
        if (count($giftaid) > 0) {
            $gaName = $giftaid[0]['fullname'] ?? '';
            $gaNameScore = $gaName ? nameMatchScore($billingName, $gaName) : 0;

            if ($gaNameScore >= 0.7) {
                $score += 20;
                $reasons[] = "giftaid_name_match";
            } else {
                $score += 10;
                $reasons[] = "giftaid_declared";
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = [
                'userid' => $candidate['id'],
                'fullname' => $candidate['fullname'],
                'score' => $score,
                'reasons' => $reasons,
            ];
        }
    }

    # Require a minimum confidence score.
    # 50 = perfect name match alone (not enough).
    # 60 = good name + some activity signal.
    # 70+ = high confidence (name + activity + postcode or previous donor).
    if ($bestMatch && $bestScore >= 60) {
        return $bestMatch;
    }

    # If score is between 50-60, report as possible but don't auto-assign
    if ($bestMatch && $bestScore >= 50) {
        $bestMatch['low_confidence'] = TRUE;
        return $bestMatch;
    }

    return NULL;
}


# ===== MAIN SCRIPT =====

# Step 1: Parse the IPN log to build a map of charge_id => billing info
$logMap = [];

if (file_exists($IPN_LOG)) {
    error_log("Parsing IPN log: $IPN_LOG");
    $handle = fopen($IPN_LOG, 'r');

    if ($handle) {
        while (($line = fgets($handle)) !== FALSE) {
            # Log format: DD-MM-YYYY hh:mm:ss:{json...}
            $jsonStart = strpos($line, '{');

            if ($jsonStart !== FALSE) {
                $jsonStr = substr($line, $jsonStart);
                $event = json_decode($jsonStr, TRUE);

                if ($event && isset($event['type']) && $event['type'] === 'charge.succeeded') {
                    $charge = $event['data']['object'];
                    $chargeId = $charge['id'];

                    $logMap[$chargeId] = [
                        'billing_email' => $charge['billing_details']['email'] ?? NULL,
                        'billing_name' => $charge['billing_details']['name'] ?? NULL,
                        'billing_postcode' => $charge['billing_details']['address']['postal_code'] ?? NULL,
                        'receipt_email' => $charge['receipt_email'] ?? NULL,
                        'customer' => $charge['customer'] ?? NULL,
                        'metadata_uid' => $charge['metadata']['uid'] ?? NULL,
                        'payment_intent' => $charge['payment_intent'] ?? NULL,
                    ];
                }
            }
        }

        fclose($handle);
    }

    error_log("Found " . count($logMap) . " charge events in IPN log");
} else {
    error_log("IPN log not found at $IPN_LOG - will use Stripe API only");
}

error_log("");

# Step 2: Get all Stripe donations misattributed to the bad user
$donations = $dbhr->preQuery("SELECT id, TransactionID, GrossAmount, timestamp, Payer, PayerDisplayName
    FROM users_donations
    WHERE userid = ? AND source = 'Stripe'
    ORDER BY timestamp DESC", [
    $BAD_USERID
]);

error_log("Found " . count($donations) . " Stripe donations attributed to user $BAD_USERID");
error_log("");

# Step 3: For each donation, try to find the real donor
$stats = [
    'total' => count($donations),
    'matched_email' => 0,
    'matched_uid' => 0,
    'matched_giftaid' => 0,
    'matched_fuzzy' => 0,
    'fuzzy_low_confidence' => 0,
    'no_billing_info' => 0,
    'no_user_match' => 0,
    'stripe_api_error' => 0,
    'not_found' => 0,
    'already_correct' => 0,
    'fixed' => 0,
];

if ($useStripeApi) {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
}

# CSV header to stdout
csvRow(['TransactionID', 'Timestamp', 'Amount', 'MatchType', 'MatchedUserID', 'MatchedUserName', 'BillingEmail', 'BillingName', 'BillingPostcode', 'Score', 'Reasons', 'Source']);

foreach ($donations as $donation) {
    $txnId = $donation['TransactionID'];

    # Get charge details from log or Stripe
    $details = getChargeDetails($txnId, $logMap, $useStripeApi);

    if ($details['source'] === 'error') {
        csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'ERROR', '', '', '', '', '', '', $details['error'] ?? 'unknown', '']);
        $stats['stripe_api_error']++;
        continue;
    }

    if (!$details['source']) {
        csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'NOT_FOUND', '', '', '', '', '', '', '', '']);
        $stats['not_found']++;
        continue;
    }

    # === Resolution chain ===

    # 1. Direct metadata uid
    if ($details['metadata_uid'] && intval($details['metadata_uid']) != $BAD_USERID) {
        $uid = intval($details['metadata_uid']);
        $u = User::get($dbhr, $dbhm, $uid);

        if ($u->getId() == $uid) {
            $stats['matched_uid']++;
            csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'metadata_uid', $uid, $u->getName(), $details['billing_email'], $details['billing_name'], $details['billing_postcode'], '', '', $details['source']]);

            if ($commit) {
                $dbhm->preExec("UPDATE users_donations SET userid = ?, Payer = ?, PayerDisplayName = ? WHERE id = ?", [
                    $uid, $u->getEmailPreferred() ?: '', $u->getName() ?: '', $donation['id']
                ]);
                $stats['fixed']++;
            }

            continue;
        }
    }

    # 2. Customer metadata/email
    $customerResult = resolveFromCustomer($details['customer'], $dbhr, $BAD_USERID);
    if ($customerResult) {
        $stats['matched_uid']++;
        $u = User::get($dbhr, $dbhm, $customerResult['userid']);
        csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], $customerResult['method'], $customerResult['userid'], $u->getName(), $details['billing_email'], $details['billing_name'], $details['billing_postcode'], '', '', $details['source']]);

        if ($commit) {
            $dbhm->preExec("UPDATE users_donations SET userid = ?, Payer = ?, PayerDisplayName = ? WHERE id = ?", [
                $customerResult['userid'], $u->getEmailPreferred() ?: '', $u->getName() ?: '', $donation['id']
            ]);
            $stats['fixed']++;
        }

        continue;
    }

    # 3. PaymentIntent metadata/email
    $piResult = resolveFromPaymentIntent($details['payment_intent'], $dbhr, $dbhm, $useStripeApi, $BAD_USERID);
    if ($piResult) {
        $stats['matched_uid']++;
        $u = User::get($dbhr, $dbhm, $piResult['userid']);
        csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], $piResult['method'], $piResult['userid'], $u->getName(), $details['billing_email'], $details['billing_name'], $details['billing_postcode'], '', '', $details['source']]);

        if ($commit) {
            $dbhm->preExec("UPDATE users_donations SET userid = ?, Payer = ?, PayerDisplayName = ? WHERE id = ?", [
                $piResult['userid'], $u->getEmailPreferred() ?: '', $u->getName() ?: '', $donation['id']
            ]);
            $stats['fixed']++;
        }

        continue;
    }

    # 4. Email match
    $billingEmail = $details['billing_email'];
    if ($billingEmail) {
        $users = $dbhr->preQuery("SELECT userid FROM users_emails WHERE email = ? AND userid != ?", [$billingEmail, $BAD_USERID]);

        if (count($users) > 0) {
            $stats['matched_email']++;
            $nameRows = $dbhr->preQuery("SELECT fullname FROM users WHERE id = ?", [$users[0]['userid']]);
            $matchedName = $nameRows[0]['fullname'] ?? '';
            csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'email', $users[0]['userid'], $matchedName, $billingEmail, $details['billing_name'], $details['billing_postcode'], '', '', $details['source']]);

            if ($commit) {
                $dbhm->preExec("UPDATE users_donations SET userid = ?, Payer = ?, PayerDisplayName = ? WHERE id = ?", [
                    $users[0]['userid'], $billingEmail, $details['billing_name'] ?: '', $donation['id']
                ]);
                $stats['fixed']++;
            }

            continue;
        }

        # Check if email belongs to the bad user (genuinely theirs)
        $ownEmail = $dbhr->preQuery("SELECT userid FROM users_emails WHERE email = ? AND userid = ?", [$billingEmail, $BAD_USERID]);
        if (count($ownEmail) > 0) {
            $stats['already_correct']++;
            csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'already_correct', $BAD_USERID, '', $billingEmail, $details['billing_name'], $details['billing_postcode'], '', '', $details['source']]);
            continue;
        }
    }

    # 5. Gift aid match — by name and/or postcode against gift aid declarations
    $billingName = $details['billing_name'];
    $billingPostcode = $details['billing_postcode'];

    # 5a. Match by name on gift aid declaration
    if ($billingName) {
        $normalized = normalizeName($billingName);

        if ($normalized && $normalized['last']) {
            $gaMatches = $dbhr->preQuery("
                SELECT g.userid, g.fullname, g.postcode AS ga_postcode, u.fullname AS user_fullname
                FROM giftaid g
                INNER JOIN users u ON g.userid = u.id
                WHERE g.deleted IS NULL
                  AND g.userid != ?
                  AND LOWER(g.fullname) LIKE ?
            ", [$BAD_USERID, '%' . $normalized['last'] . '%']);

            foreach ($gaMatches as $ga) {
                $gaScore = nameMatchScore($billingName, $ga['fullname']);

                # Check postcode corroboration
                $postcodeMatch = FALSE;
                if ($billingPostcode && $ga['ga_postcode']) {
                    $gaPC = strtoupper(preg_replace('/\s+/', '', $ga['ga_postcode']));
                    $stripePC = strtoupper(preg_replace('/\s+/', '', $billingPostcode));
                    $postcodeMatch = ($gaPC === $stripePC);
                }

                # Name + postcode together is a strong match even with a weaker name score.
                # Name alone needs a higher bar.
                $matched = ($gaScore >= 0.5 && $postcodeMatch) || ($gaScore >= 0.7);

                if ($matched) {
                    $reasons = 'giftaid_name=' . $ga['fullname'];
                    if ($postcodeMatch) {
                        $reasons .= ',giftaid_postcode_exact';
                    }

                    $stats['matched_giftaid']++;
                    csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'giftaid', $ga['userid'], $ga['user_fullname'], $billingEmail, $billingName, $billingPostcode, round($gaScore * 100), $reasons, $details['source']]);

                    if ($commit) {
                        $dbhm->preExec("UPDATE users_donations SET userid = ?, Payer = ?, PayerDisplayName = ? WHERE id = ?", [
                            $ga['userid'],
                            $billingEmail ?: '',
                            ($billingName ?: '') . ' [giftaid]',
                            $donation['id']
                        ]);
                        $stats['fixed']++;
                    }

                    continue 2;
                }
            }
        }
    }

    # 5b. Match by postcode on gift aid declaration (when no billing name available)
    if ($billingPostcode) {
        $stripePC = strtoupper(preg_replace('/\s+/', '', $billingPostcode));

        $gaByPC = $dbhr->preQuery("
            SELECT g.userid, g.fullname, g.postcode AS ga_postcode, u.fullname AS user_fullname
            FROM giftaid g
            INNER JOIN users u ON g.userid = u.id
            WHERE g.deleted IS NULL
              AND g.userid != ?
              AND REPLACE(UPPER(g.postcode), ' ', '') = ?
        ", [$BAD_USERID, $stripePC]);

        if (count($gaByPC) == 1) {
            # Unique postcode match — only trust if exactly one gift aid declaration at this postcode
            $ga = $gaByPC[0];
            $reasons = 'giftaid_postcode=' . $ga['ga_postcode'];

            # If we also have a billing name, check it corroborates
            $nameOk = TRUE;
            if ($billingName) {
                $gaScore = nameMatchScore($billingName, $ga['fullname']);
                if ($gaScore < 0.3) {
                    $nameOk = FALSE;  # Name actively contradicts — don't match
                } elseif ($gaScore >= 0.5) {
                    $reasons .= ',giftaid_name_partial=' . $ga['fullname'];
                }
            }

            if ($nameOk) {
                $stats['matched_giftaid']++;
                csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'giftaid_postcode', $ga['userid'], $ga['user_fullname'], $billingEmail, $billingName, $billingPostcode, '', $reasons, $details['source']]);

                if ($commit) {
                    $dbhm->preExec("UPDATE users_donations SET userid = ?, Payer = ?, PayerDisplayName = ? WHERE id = ?", [
                        $ga['userid'],
                        $billingEmail ?: '',
                        ($billingName ?: '') . ' [giftaid_pc]',
                        $donation['id']
                    ]);
                    $stats['fixed']++;
                }

                continue;
            }
        }
    }

    # 6. Fuzzy name + activity + postcode matching

    $fuzzyResult = fuzzyMatchUser($billingName, $billingPostcode, $donation['timestamp'], $dbhr, $BAD_USERID);

    if ($fuzzyResult && empty($fuzzyResult['low_confidence'])) {
        $stats['matched_fuzzy']++;
        $reasonStr = implode(', ', $fuzzyResult['reasons']);
        csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'fuzzy', $fuzzyResult['userid'], $fuzzyResult['fullname'], $billingEmail, $billingName, $billingPostcode, $fuzzyResult['score'], $reasonStr, $details['source']]);

        if ($commit) {
            $u = User::get($dbhr, $dbhm, $fuzzyResult['userid']);
            # Tag as fuzzy match so it's clear this was an automated best-guess
            $displayName = ($billingName ?: '') . ' [fuzzy:' . $fuzzyResult['score'] . ']';
            $dbhm->preExec("UPDATE users_donations SET userid = ?, Payer = ?, PayerDisplayName = ? WHERE id = ?", [
                $fuzzyResult['userid'],
                $billingEmail ?: ($u->getEmailPreferred() ?: ''),
                $displayName,
                $donation['id']
            ]);
            $stats['fixed']++;
        }

        continue;
    }

    if ($fuzzyResult && !empty($fuzzyResult['low_confidence'])) {
        $stats['fuzzy_low_confidence']++;
        $reasonStr = implode(', ', $fuzzyResult['reasons']);
        csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'fuzzy_low', $fuzzyResult['userid'], $fuzzyResult['fullname'], $billingEmail, $billingName, $billingPostcode, $fuzzyResult['score'], $reasonStr, $details['source']]);
        continue;
    }

    # 6. No match found
    $stats['no_user_match']++;
    csvRow([$txnId, $donation['timestamp'], $donation['GrossAmount'], 'NO_MATCH', '', '', $billingEmail, $billingName, $billingPostcode, '', '', $details['source']]);

    # Store billing details even if we can't match, and remove bad userid
    if ($commit) {
        $dbhm->preExec("UPDATE users_donations SET userid = NULL, Payer = ?, PayerDisplayName = ? WHERE id = ?", [
            $billingEmail ?: '',
            $billingName ?: '',
            $donation['id']
        ]);
        $stats['fixed']++;
    }
}

error_log("");
error_log("=== Summary ===");
error_log("Total Stripe donations on user $BAD_USERID: {$stats['total']}");
error_log("Matched by uid (metadata/customer/PI): {$stats['matched_uid']}");
error_log("Matched by email: {$stats['matched_email']}");
error_log("Matched by gift aid declaration: {$stats['matched_giftaid']}");
error_log("Matched by fuzzy (name+activity+postcode): {$stats['matched_fuzzy']}");
error_log("Possible fuzzy (low confidence, not assigned): {$stats['fuzzy_low_confidence']}");
error_log("Actually belong to user $BAD_USERID: {$stats['already_correct']}");
error_log("No billing info at all: {$stats['no_billing_info']}");
error_log("No match found: {$stats['no_user_match']}");
error_log("Stripe API errors: {$stats['stripe_api_error']}");
error_log("Not found in log or Stripe: {$stats['not_found']}");

if ($commit) {
    error_log("Fixed: {$stats['fixed']}");
} else {
    error_log("");
    error_log("This was a DRY RUN. Re-run with --commit to apply changes.");
}

# Step 4: Report on the empty email record that causes the problem
error_log("");
error_log("=== Root Cause ===");
$emptyEmails = $dbhr->preQuery("SELECT id, userid FROM users_emails WHERE email = ''");
error_log("Empty email records in users_emails: " . count($emptyEmails));

foreach ($emptyEmails as $ee) {
    error_log("  users_emails.id={$ee['id']} userid={$ee['userid']}");
}

error_log("DELETE this record to prevent future misattribution:");
error_log("  DELETE FROM users_emails WHERE id = 130512179;");

if ($commit) {
    error_log("");
    error_log("Deleting empty email record (users_emails id=130512179)...");
    $dbhm->preExec("DELETE FROM users_emails WHERE id = ? AND email = ''", [130512179]);
    error_log("Done.");
}

# Step 5: Scan Stripe for charges we never recorded
if ($findMissing) {
    error_log("");
    error_log("=== Scanning Stripe for Missing Donations ===");

    if (!$useStripeApi) {
        error_log("ERROR: --find-missing requires Stripe API (don't use --no-stripe-api)");
    } else {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        # Get all TransactionIDs we already have, for fast lookup
        $existing = $dbhr->preQuery("SELECT TransactionID FROM users_donations WHERE TransactionID IS NOT NULL");
        $existingMap = [];

        foreach ($existing as $row) {
            $existingMap[$row['TransactionID']] = TRUE;
        }

        error_log("Have " . count($existingMap) . " existing transaction IDs in database");

        $missingCount = 0;
        $missingTotal = 0;
        $inserted = 0;
        $hasMore = TRUE;
        $startingAfter = NULL;
        $scanned = 0;

        while ($hasMore) {
            $params = [
                'limit' => 100,
            ];

            if ($startingAfter) {
                $params['starting_after'] = $startingAfter;
            }

            $charges = \Stripe\Charge::all($params);

            foreach ($charges->data as $charge) {
                $scanned++;
                $startingAfter = $charge->id;

                # Skip failed charges
                if ($charge->status !== 'succeeded') {
                    continue;
                }

                # Skip PayPal (handled by PayPal IPN)
                $method = $charge->payment_method_details->type ?? NULL;

                if ($method === 'paypal') {
                    continue;
                }

                # Check if we have this charge
                if (!isset($existingMap[$charge->id])) {
                    $amount = $charge->amount / 100;
                    $billingEmail = $charge->billing_details->email ?? ($charge->receipt_email ?? NULL);
                    $billingName = $charge->billing_details->name ?? NULL;
                    $metadataUid = $charge->metadata->uid ?? NULL;
                    $recurring = $charge->description === 'Subscription creation';

                    # Try to find the user
                    $userId = NULL;

                    if ($metadataUid) {
                        $userId = intval($metadataUid);
                    } elseif ($billingEmail) {
                        $emailUsers = $dbhr->preQuery("SELECT userid FROM users_emails WHERE email = ? AND email != ''", [$billingEmail]);

                        if (count($emailUsers) > 0) {
                            $userId = $emailUsers[0]['userid'];
                        }
                    }

                    $missingCount++;
                    $missingTotal += $amount;

                    $date = date("Y-m-d H:i:s", $charge->created);
                    error_log("  MISSING: {$charge->id} ({$date}, £{$amount})" .
                        " email=" . ($billingEmail ?: 'none') .
                        " name=" . ($billingName ?: 'none') .
                        " => user " . ($userId ?: 'NULL'));

                    if ($commit) {
                        $d = new Donations($dbhr, $dbhm);
                        $did = $d->add(
                            $userId,
                            $billingEmail ?: '',
                            $billingName ?: '',
                            $date,
                            $charge->id,
                            $amount,
                            Donations::TYPE_STRIPE,
                            $recurring ? 'subscr_payment' : NULL,
                            Donations::TYPE_STRIPE
                        );
                        error_log("    Inserted as donation id $did");
                        $inserted++;
                    }
                }
            }

            $hasMore = $charges->has_more;
        }

        error_log("");
        error_log("Scanned $scanned Stripe charges");
        error_log("Missing from database: $missingCount (£" . number_format($missingTotal, 2) . ")");

        if ($commit) {
            error_log("Inserted: $inserted");
        }
    }
}
