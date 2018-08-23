<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

function verify($email) {
    $url = "https://bpi.briteverify.com/emails.json?&address=" . urlencode($email). "&amp;apikey=" . BRITEVERIFY_PRIVATE_KEY;
    error_log("Query $url");
    $c=curl_init($url);

    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_FAILONERROR, false);
    curl_setopt($c, CURLOPT_TIMEOUT, 5);

    $results=curl_exec($c);
    $info=curl_getinfo($c);
    curl_close($c);

    $answer=json_decode($results, TRUE);
    #error_log("Result " . var_export($answer, TRUE));

    if ($info['http_code'] && isset($answer['status'])) {
        # We have a result.
    } else {
        $answer = NULL;
    }

    return($answer);
}

for ($year = 2003; $year < 2018; $year++) {
    for ($month = 1; $month < 13; $month++) {
        $date = new DateTime();
        $date->setDate($year, $month, 1);

        $start = $date->format('Y-m-d');
        $end = $date->format('Y-m-t');
        error_log("$start -> $end");

        # Look for emails where:
        # - we haven't already verified - no point doing it again
        # - there are memberships of a Freegle group - otherwise we don't care
        # - the email has not bouncing - if it has, we already know something useful about the email state, even if
        #   it's not bouncing now, and that's probably more reliable than BriteVerify
        # - they've not been active for a year - otherwise there's a reasonable chance the email is ok.
        # - we're sending digest, events or volunteer emails - and therefore likely to hit spam traps.
        $sql = "SELECT users_emails.id, users_emails.email FROM users_emails LEFT JOIN users_emails_verify ON users_emails.id = users_emails_verify.emailid INNER JOIN memberships ON memberships.userid = users_emails.userid INNER JOIN groups ON groups.id = memberships.groupid INNER JOIN users ON users.id = users_emails.userid WHERE users_emails_verify.emailid IS NULL AND bounced IS NULL AND groups.type = 'Freegle' AND users_emails.added BETWEEN '$start' AND '$end' AND (emailfrequency != 0 OR eventsallowed != 0 OR volunteeringallowed != 0) AND bouncing = 0 AND bounced IS NULL GROUP BY users_emails.id, users_emails.email ORDER BY users_emails.added ASC LIMIT 50;";
        $emails = $dbhr->preQuery($sql);
        error_log("...scan " . count($emails));

        foreach ($emails as $e) {
            $email = $e['email'];

            # Don't check our own domains.
            if (!ourDomain($email)) {
                $id = $e['id'];
                error_log("{$email}");
                $p = strpos($email, '@');

                if ($p) {
                    $domain = substr($email, $p + 1);

                    # Check if we have already established that this domain can't be queried.
                    $domains = $dbhr->preQuery("SELECT * FROM users_emails_verify_domains WHERE domain LIKE ?;", [
                        $domain
                    ]);

                    if (count($domains) > 0) {
                        error_log("...domain accepts all ");
                        $dbhm->preExec("REPLACE INTO users_emails_verify (emailid, status) VALUES (?, ?);", [
                            $id,
                            'accept_all'
                        ]);
                    } else {
                        $res = verify($email);

                        if ($res) {
                            # Store the result.
                            $dbhm->preExec("REPLACE INTO users_emails_verify (emailid, result, status) VALUES (?, ?, ?);", [
                                $id,
                                json_encode($res),
                                $res['status']
                            ]);

                            if ($res['status'] == 'accept_all') {
                                # This domain can't be queried.  No point blowing our allowance on it in future.
                                $dbhm->preExec("REPLACE INTO users_emails_verify_domains (domain, reason) VALUES (?, ?);", [
                                    $domain,
                                    json_encode($res)
                                ]);
                            }
                        }
                    }
                } else {
                    error_log("...invalid email {$email}");
                }
            }
        }
    }
}
