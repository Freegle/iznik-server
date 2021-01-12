<?php
namespace Freegle\Iznik;

define( 'BASE_DIR', dirname(__FILE__) . '/..' );
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/composer/vendor/cviebrock/discourse-php/src/Exception/PayloadException.php');
require_once(IZNIK_BASE . '/composer/vendor/cviebrock/discourse-php/src/SSOHelper.php');

$sso = new \Cviebrock\DiscoursePHP\SSOHelper();
$sso->setSecret(FORUM_SECRET);

$payload = $_GET['sso'];
$signature = $_GET['sig'];

error_log("Validate $payload, $signature");

if (($sso->validatePayload($payload,$signature))) {
    error_log("Validated");
    $nonce = $sso->getNonce($payload);

    $persistent = NULL;

    if (array_key_exists('Iznik-Forum-SSO', $_COOKIE)) {
        $persistent = json_decode($_COOKIE['Iznik-Forum-SSO'], TRUE);

        try {
            # First try via the DB.  This avoids issues where we've just logged in and our session is not up
            # to date.
            $sessions = $dbhr->preQuery("SELECT * FROM sessions WHERE id = ? AND series = ? AND token = ?;", [
                $persistent['id'],
                $persistent['series'],
                $persistent['token']
            ], FALSE);

            if( count($sessions)==0){
                error_log('forum_sso - no login sessions');
                echo "You're not logged in.";
                exit(0);
            }

            foreach ($sessions as &$session) {
                $u = new User($dbhr, $dbhm, $session['userid']);

                $atts = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, MessageCollection::APPROVED, FALSE);

                $memberships = $u->getMemberships(FALSE, Group::GROUP_FREEGLE);
                $grouplist = [];
                
                foreach ($memberships as $membership) {
                    $grouplist[] = $membership['namedisplay'];
                }

                # Save the info we need for login.
                $session['name'] = $u->getName(); // str_replace(' ', '', $u->getName());
                $session['avatar_url'] = $atts['profile']['url'];
                $session['admin'] = $u->isAdmin();
                $session['email'] = $u->getEmailPreferred();
                $session['grouplist'] = substr(implode(',', $grouplist),0,1000);  // Actual max is 3000 but 1000 is enough
                $session['mod'] = $u->isModerator();
                error_log("Group list is {$session['grouplist']}");
            }
        } catch (\Exception $e) {
            error_log("forum_sso - DB failed with " . $e->getMessage());
        }

        foreach ($sessions as $session) {
            if ($persistent['id'] == $session['id'] &&
                $persistent['series'] == $session['series'] &&
                $persistent['token'] == $session['token']
            ) {
                # We have found our session and can therefore log in.
                $extraParameters = array(
                    'username' => $session['name'],
                    'name' => $session['name'],
                    'avatar_url' => $session['avatar_url'],
                    'admin' => $session['admin'],
                    'bio' => Utils::presdef('mod', $session) ? "Freegle Volunteer on {$session['grouplist']}" : "Member on {$session['grouplist']}"
                );

                $refer = 'https://forum.ilovefreegle.org';
                $query = $sso->getSignInString($nonce, $session['userid'], $session['email'], $extraParameters);
                header('Location: ' . $refer . '/session/sso_login?' . $query);
                error_log('forum_sso - logged in '.$session['name']);
                exit(0);
            }
        }
        error_log('forum_sso - dropped out');
    } else {
        error_log("No cookie");
    }
} else {
    error_log("Failed validation");
}

error_log('forum_sso - redirect to login');

// Redirect.  This will force sign-in, which will set up the cookie, then redirect back here so that
// we then successfully log in.
header('Location: http://ilovefreegle.org/forum' );
die();
