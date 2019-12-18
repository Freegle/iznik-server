<?php

define( 'BASE_DIR', dirname(__FILE__) . '/..' );
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/session/Session.php');

global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/composer/vendor/cviebrock/discourse-php/src/Exception/PayloadException.php');
require_once(IZNIK_BASE . '/composer/vendor/cviebrock/discourse-php/src/SSOHelper.php');

$sso = new Cviebrock\DiscoursePHP\SSOHelper();
$sso->setSecret(DISCOURSE_SECRET);

$payload = $_GET['sso'];
$signature = $_GET['sig'];

if (($sso->validatePayload($payload,$signature))) {
    $nonce = $sso->getNonce($payload);

    $persistent = NULL;

    if (array_key_exists('Iznik-Discourse-SSO', $_COOKIE)) {
        $persistent = json_decode($_COOKIE['Iznik-Discourse-SSO'], TRUE);

        try {
            # First try via the DB.  This avoids issues where we've just logged in and our session is not up
            # to date.
            $sessions = $dbhr->preQuery("SELECT sessions.* FROM sessions INNER JOIN users ON sessions.userid = users.id WHERE users.systemrole IN ('Admin', 'Support', 'Moderator') AND sessions.id = ? AND sessions.series = ? AND sessions.token = ?;", [
                $persistent['id'],
                $persistent['series'],
                $persistent['token']
            ], FALSE, FALSE);

            if( count($sessions)==0){
              error_log('discourse_sso - no login sessions');
              echo "You have no MT login sessions. This might mean that you aren't the moderator of a Freegle group yet. Please check at <a href='https://modtools.org/' target='_blank'>https://modtools.org</a>.";
              exit(0);
            }
            foreach ($sessions as &$session) {
                $u = new User($dbhr, $dbhm, $session['userid']);

                if ($u->isModerator()) {
                    $ctx = NULL;
                    $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE, MessageCollection::APPROVED, FALSE);

                    $memberships = $u->getMemberships(TRUE, Group::GROUP_FREEGLE);

                    if (count($memberships) === 0) {
                        # Not a Freegle mod.
                        error_log('discourse_sso - Not a Freegle mod');
                        echo "You are not a moderator of a Freegle group";
                        exit(0);
                    }

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
                } else {
                  error_log('discourse_sso - Not a mod: '.$u->getEmailPreferred());
                  echo "You are not a moderator";
                  exit(0);
                }
            }
        } catch (Exception $e) {
            error_log("discourse_sso - DB failed with " . $e->getMessage());

            # Instead try in our flat file.  We use a flat file so that we can sign in if the DB is unavailable.
            $sessions = json_decode(file_get_contents('/var/www/iznik/iznik_sessions'), TRUE);
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
                    'bio' => $session['email'] . "\r\n\r\nis a mod on " . $session['grouplist']
                );

                $refer = 'https://discourse.ilovefreegle.org';
//                if (array_key_exists('HTTP_REFERER', $_SERVER)) {
//                    $refer = $_SERVER['HTTP_REFERER'];
//                }
                $query = $sso->getSignInString($nonce, $session['userid'], $session['email'], $extraParameters);
                header('Location: ' . $refer . '/session/sso_login?' . $query);
                error_log('discourse_sso - logged in '.$session['name']);
                exit(0);
            }
        }
      error_log('discourse_sso - dropped out');
    }
}
error_log('discourse_sso - redirect to MT login');

// Redirect.  This will force sign-in, which will set up the cookie, then redirect back here so that
// we then successfully log in.
header('Location: http://modtools.org/discourse' );
die();
