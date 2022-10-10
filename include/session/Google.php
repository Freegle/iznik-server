<?php
namespace Freegle\Iznik;

require_once("/etc/iznik.conf");

class Google
{
    /** @var LoggedPDO $dbhr */
    /** @var LoggedPDO $dbhm */
    private $dbhr;
    private $dbhm;
    private $access_token;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $mobile)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->client = new \Google_Client();
        $this->client->setApplicationName(GOOGLE_APP_NAME);

        $this->client->setClientId(GOOGLE_CLIENT_ID);
        $this->client->setClientSecret(GOOGLE_CLIENT_SECRET);

        # This is required for the mobile app to work.  For some reason.
        if ($mobile) {
            $this->client->setRedirectUri('http://localhost');
        } else {
            $this->client->setRedirectUri('postmessage');
        }

        $this->client->setAccessType('offline');
        $this->client->setScopes(array(
            'https://www.googleapis.com/auth/email',
            'https://www.googleapis.com/auth/profile'));

        $this->plus = new \Google_Service_Plus($this->client);

        return ($this);
    }

    public function getClient() {
        return($this->client);
    }

    public function getPlus() {
        return($this->plus);
    }

    public function getUserDetails($url) {
        $ret = @file_get_contents($url);
        $userData = NULL;

        if ($ret) {
            $userData = json_decode($ret);
        }

        return($userData);
    }

    // Used by pre-Nuxt3 code.
    function login($code = NULL, $token = NULL)
    {
        $ret = 2;
        $status = 'Login failed';
        $s = NULL;

        try {
            $client = $this->getClient();

            $this->access_token = $token;

            if ($code) {
                $client->authenticate($code);
                $this->access_token = $client->getAccessToken();
            }

            $googlemail = NULL;
            $googleuid  = NULL;
            $firstname  = NULL;
            $lastname   = NULL;
            $fullname   = NULL;
            $this->tokens_decoded = NULL;

            if ($this->access_token) {
                $this->tokens_decoded = json_decode($this->access_token);
            }

            if ($this->tokens_decoded) {
                $url = 'https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . $this->tokens_decoded->access_token;
                $userData = $this->getUserDetails($url);

                if ($userData) {
                    $googleuid  = isset($userData->id) ? $userData->id : NULL;
                    $googlemail = isset($userData->email) ? $userData->email : NULL;
                    $fullname   = isset($user->name) ? $userData->name : NULL;
                    $firstname  = isset($user->given_name) ? $userData->given_name : NULL;
                    $lastname   = isset($user->family_name) ? $userData->family_name : NULL;
                }
            }

            if ($googleuid) {
                #error_log("Google id " . var_export($googleuid, TRUE));

                # See if we know this user already.  We might have an entry for them by email, or by Facebook ID.
                $u = User::get($this->dbhr, $this->dbhm);
                $eid = $googlemail ? $u->findByEmail($googlemail) : NULL;
                $gid = $googleuid ? $u->findByLogin('Google', $googleuid) : NULL;
                #error_log("Email $eid  from $googlemail Google $gid, f $firstname, l $lastname, full $fullname");

                if ($eid && $gid && $eid != $gid) {
                    # This is a duplicate user.  Merge them.
                    $u = User::get($this->dbhr, $this->dbhm);
                    $u->merge($eid, $gid, "Google Login - GoogleID $gid, Email $googlemail = $eid");
                }

                $id = $eid ? $eid : $gid;
                #error_log("Login id $id from $eid and $gid");

                if (!$id) {
                    # We don't know them.  Create a user.
                    #
                    # There's a timing window here, where if we had two first-time logins for the same user,
                    # one would fail.  Bigger fish to fry.
                    #
                    # We don't have the firstname/lastname split, only a single name.  Way two go.
                    $id = $u->create($firstname, $lastname, $fullname, "Google login from $gid");

                    if ($id) {
                        # Make sure that we have the email recorded as one of the emails for this user.
                        $u = User::get($this->dbhr, $this->dbhm, $id);

                        if ($googlemail) {
                            $u->addEmail($googlemail, 0, FALSE);
                        }

                        # Now Set up a login entry.  Use IGNORE as there is a timing window here.
                        $rc = $this->dbhm->preExec(
                            "INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?,'Google',?);",
                            [
                                $id,
                                $googleuid
                            ]
                        );

                        $id = $rc ? $id : NULL;
                    }
                } else {
                    # We know them - but we might not have all the details.
                    $u = User::get($this->dbhr, $this->dbhm, $id);

                    if (!$eid) {
                        $u->addEmail($googlemail, 0, FALSE);
                    }

                    if (!$gid) {
                        $this->dbhm->preExec(
                            "INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?,'Google',?);",
                            [
                                $id,
                                $googleuid
                            ]
                        );
                    }
                }

                # Save off the access token, which we might need, and update the access time.
                $this->dbhm->preExec("UPDATE users_logins SET lastaccess = NOW(), credentials = ? WHERE userid = ? AND type = 'Google';",
                                     [
                                         (string)$this->access_token,
                                         $id
                                     ]);

                # We have publish permissions for users who login via our platform.
                $u->setPrivate('publishconsent', 1);

                # We might have syncd the membership without a good name.
                if (!$u->getPrivate('fullname')) {
                    $u->setPrivate('firstname', $firstname);
                    $u->setPrivate('lastname', $lastname);
                    $u->setPrivate('fullname', $fullname);
                }

                if ($id) {
                    # We are logged in.
                    $s = new Session($this->dbhr, $this->dbhm);
                    $s->create($id);

                    User::clearCache($id);

                    $l = new Log($this->dbhr, $this->dbhm);
                    $l->log([
                                'type' => Log::TYPE_USER,
                                'subtype' => Log::SUBTYPE_LOGIN,
                                'byuser' => $id,
                                'text' => "Using Google $googleuid"
                            ]);

                    $ret = 0;
                    $status = 'Success';
                }
            }
        } catch (\Exception $e) {
            $ret = 2;
            $status = "Didn't manage to get a Google session: " . $e->getMessage();
            error_log("Didn't get a Google session " . $e->getMessage());
        }

        return ([$s, [ 'ret' => $ret, 'status' => $status]]);
    }

    // Used by Nuxt3 code.
    function loginWithJWT($JWT)
    {
        $ret = 2;
        $status = 'Login failed2';
        $s = NULL;

        try {
            $client = $this->getClient();
            $payload = $client->verifyIdToken($JWT);

            if ($payload) {
                $atts = $payload->getAttributes();

                if (array_key_exists('payload', $atts)) {
                    $payload = $atts['payload'];

                    $googleuid = Utils::presdef('sub', $payload, NULL);
                    $googlemail = Utils::presdef('email', $payload, NULL);
                    $fullname = Utils::presdef('name', $payload, NULL);
                    $firstname = Utils::presdef('given_name', $payload, NULL);
                    $lastname = Utils::presdef('family_name', $payload, NULL);

                    if ($googleuid) {
                        #error_log("Google id " . var_export($googleuid, TRUE));

                        # See if we know this user already.  We might have an entry for them by email, or by Facebook ID.
                        $u = User::get($this->dbhr, $this->dbhm);
                        $eid = $googlemail ? $u->findByEmail($googlemail) : NULL;
                        $gid = $googleuid ? $u->findByLogin('Google', $googleuid) : NULL;
                        #error_log("Email $eid  from $googlemail Google $gid, f $firstname, l $lastname, full $fullname");

                        if ($eid && $gid && $eid != $gid) {
                            # This is a duplicate user.  Merge them.
                            $u = User::get($this->dbhr, $this->dbhm);
                            $u->merge($eid, $gid, "Google Login - GoogleID $gid, Email $googlemail = $eid");
                        }

                        $id = $eid ? $eid : $gid;
                        #error_log("Login id $id from $eid and $gid");

                        if (!$id) {
                            # We don't know them.  Create a user.
                            #
                            # There's a timing window here, where if we had two first-time logins for the same user,
                            # one would fail.  Bigger fish to fry.
                            #
                            # We don't have the firstname/lastname split, only a single name.  Way two go.
                            $id = $u->create($firstname, $lastname, $fullname, "Google login from $gid");

                            if ($id) {
                                # Make sure that we have the email recorded as one of the emails for this user.
                                $u = User::get($this->dbhr, $this->dbhm, $id);

                                if ($googlemail) {
                                    $u->addEmail($googlemail, 0, FALSE);
                                }

                                # Now Set up a login entry.  Use IGNORE as there is a timing window here.
                                $rc = $this->dbhm->preExec(
                                    "INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?,'Google',?);",
                                    [
                                        $id,
                                        $googleuid
                                    ]
                                );

                                $id = $rc ? $id : NULL;
                            }
                        } else {
                            # We know them - but we might not have all the details.
                            $u = User::get($this->dbhr, $this->dbhm, $id);

                            if (!$eid) {
                                $u->addEmail($googlemail, 0, FALSE);
                            }

                            if (!$gid) {
                                $this->dbhm->preExec(
                                    "INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?,'Google',?);",
                                    [
                                        $id,
                                        $googleuid
                                    ]
                                );
                            }
                        }

                        # Save off the access token, which we might need, and update the access time.
                        $this->dbhm->preExec("UPDATE users_logins SET lastaccess = NOW(), credentials = ? WHERE userid = ? AND type = 'Google';",
                                             [
                                                 (string)$this->access_token,
                                                 $id
                                             ]);

                        # We have publish permissions for users who login via our platform.
                        $u->setPrivate('publishconsent', 1);

                        # We might have syncd the membership without a good name.
                        if (!$u->getPrivate('fullname')) {
                            $u->setPrivate('firstname', $firstname);
                            $u->setPrivate('lastname', $lastname);
                            $u->setPrivate('fullname', $fullname);
                        }

                        if ($id) {
                            # We are logged in.
                            $s = new Session($this->dbhr, $this->dbhm);
                            $s->create($id);

                            User::clearCache($id);

                            $l = new Log($this->dbhr, $this->dbhm);
                            $l->log([
                                        'type' => Log::TYPE_USER,
                                        'subtype' => Log::SUBTYPE_LOGIN,
                                        'byuser' => $id,
                                        'text' => "Using Google $googleuid"
                                    ]);

                            $ret = 0;
                            $status = 'Success';
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $ret = 2;
            $status = "Didn't manage to get a Google session: " . $e->getMessage();
            error_log("Didn't get a Google session " . $e->getMessage());
        }

        return ([$s, [ 'ret' => $ret, 'status' => $status]]);
    }
}