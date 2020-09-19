<?php
namespace Freegle\Iznik;

require_once("/etc/iznik.conf");
require_once(IZNIK_BASE . '/lib/openid.php');

class Yahoo
{
    /** @var LoggedPDO $dbhr */
    /** @var LoggedPDO $dbhm */
    private $dbhr;
    private $dbhm;

    /** @var  $openid LightOpenID */
    private $openid;

    private static $instance;
    private $host = NULL;

    public static function getInstance($dbhr, $dbhm, $host = NULL)
    {
        if (!isset(self::$instance)) {
            self::$instance = new Yahoo($dbhr, $dbhm, $host);
        }
        return self::$instance;
    }

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $host = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->host = $host ? $host : (Utils::getProtocol() . $_SERVER['HTTP_HOST']);

        $this->openid = new \LightOpenID($host);
        $this->openid->realm = $this->host;

        return ($this);
    }
    
    /**
     * @param mixed $openid
     */
    public function setOpenid($openid)
    {
        $this->openid = $openid;
    }

    function loginWithCode($code, $loginoverride = NULL, $guidoverride = NULL, $userinfooverride = NULL) {
        $ret = NULL;

        # New style Yahoo login as of 2020.  The client has obtained an authorization code from Yahoo using the flow
        # in https://developer.yahoo.com/oauth2/guide/flows_authcode/.  We now try to convert that code to an Access
        # Token which we can use to obtain the user info.
        #
        # We do not have an interest in the Refresh Token, because our own sessions are long-lived and we don't
        # access Yahoo after login has completed.
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.login.yahoo.com/oauth2/get_token');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization: Basic " . base64_encode(YAHOO_CLIENT_ID . ':' . YAHOO_CLIENT_SECRET),
            "Content-type: application/x-www-form-urlencoded"
        ]);
        $params = 'client_id=' . urlencode(YAHOO_CLIENT_ID) . '&client_secret=' . urlencode(YAHOO_CLIENT_SECRET) . '&redirect_uri=oob&code=' . $code . '&grant_type=authorization_code';
        error_log("Login with code $code params $params");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
        $json_response = $loginoverride ? $loginoverride : curl_exec($curl);
        $status = $loginoverride ? 200 : curl_getinfo($curl, CURLINFO_HTTP_CODE);
        error_log("Yahoo login status 1 $status, JSON $json_response");

        if ($status == 200) {
            $json = json_decode($json_response, TRUE);
            $token = $json['access_token'];

            if ($token) {
                # We have an access token.  Success.  Now get the user info.
                curl_close($curl);
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, 'https://api.login.yahoo.com/openid/v1/userinfo');
                curl_setopt($curl, CURLOPT_TIMEOUT, 60);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer $token"
                ]);

                $json_response = $userinfooverride ? $userinfooverride : curl_exec($curl);
                $status = $userinfooverride ? 200 : curl_getinfo($curl, CURLINFO_HTTP_CODE);
                error_log("Yahoo login status 2 $status");

                if ($status == 200) {
                    error_log("Got user info $json_response");
                    $attrs = json_decode($json_response, TRUE);
                    $givenName = Utils::presdef('given_name', $attrs, NULL);
                    $familyName = Utils::presdef('family_name', $attrs, NULL);
                    $email = Utils::presdef('email', $attrs, NULL);

                    error_log("$givenName, $familyName, $email");

                    if ($email) {
                        # We're in.
                        #
                        # See if we know this user already.  We might have an entry for them by email, or by their
                        # Yahoo ID (which in this method we are assuming is the returned preferred_username).
                        $u = User::get($this->dbhr, $this->dbhm);
                        $id = $email ? $u->findByEmail($email) : NULL;
                        error_log("Found $id for $email");

                        if (!$id) {
                            # We don't know them.  Create a user.
                            #
                            # There's a timing window here, where if we had two first-time logins for the same user,
                            # one would fail.  Bigger fish to fry.
                            $id = $u->create($givenName, $familyName, NULL, "Yahoo new-style login from $email");

                            if ($id) {
                                $u = User::get($this->dbhr, $this->dbhm, $id);

                                if ($email) {
                                    $u->addEmail($email, 1, TRUE);
                                }

                                # Now Set up a login entry.
                                $rc = $this->dbhm->preExec(
                                    "INSERT INTO users_logins (userid, type, uid) VALUES (?,'Yahoo',?);",
                                    [
                                        $id,
                                        $email
                                    ]
                                );

                                $id = $rc ? $id : NULL;
                            }
                        }

                        $u = User::get($this->dbhr, $this->dbhm, $id);

                        # We have publish permissions for users who login via our platform.
                        $u->setPrivate('publishconsent', 1);

                        $this->dbhm->preExec("UPDATE users_logins SET lastaccess = NOW() WHERE userid = ? AND type = 'Yahoo';",
                            [
                                $id
                            ]);

                        if ($id) {
                            error_log("Logged in");
                            # We are logged in.
                            $s = new Session($this->dbhr, $this->dbhm);
                            $s->create($id);

                            # Anyone who has logged in to our site has given RIPA consent.
                            $this->dbhm->preExec("UPDATE users SET ripaconsent = 1 WHERE id = ?;",
                                [
                                    $id
                                ]);
                            User::clearCache($id);

                            $l = new Log($this->dbhr, $this->dbhm);
                            $l->log([
                                'type' => Log::TYPE_USER,
                                'subtype' => Log::SUBTYPE_LOGIN,
                                'byuser' => $id,
                                'text' => 'Using Yahoo'
                            ]);

                            error_log("Returning success");
                            $ret = [$s, ['ret' => 0, 'status' => 'Success']];
                        }
                    }
                }
            }
        }

        return $ret;
    }

    function login($returnto = '/')
    {
        try
        {
            $loginurl = "{$this->host}/yahoologin?returnto=" . urlencode($returnto);
            $this->openid->returnUrl = $loginurl;

            if (($this->openid->validate()) &&
                ($this->openid->identity != 'https://open.login.yahooapis.com/openid20/user_profile/xrds'))
            {
                $attrs = $this->openid->getAttributes();

                # The Yahoo ID is derived from the email; Yahoo should always returns the Yahoo email even if a different
                # email is configured on the profile.  Way to go.
                #
                # But sometimes it doesn't return the email at all.  Way to go.  So in that case we use the namePerson
                # as though it was a Yahoo ID, since we have no other way to get it, and proceed without adding an
                # email.
                $yahooid = Utils::pres('contact/email', $attrs) ? $attrs['contact/email'] : $attrs['namePerson'];
                $p = strpos($yahooid, "@");
                $yahooid = $p != FALSE ? substr($yahooid, 0, $p) : $yahooid;

                # See if we know this user already.  We might have an entry for them by email, or by Yahoo ID.
                $u = User::get($this->dbhr, $this->dbhm);
                $eid = Utils::pres('contact/email', $attrs) ? $u->findByEmail($attrs['contact/email']) : NULL;
                $yid = $u->findByYahooId($yahooid);
                #error_log("Email $eid  from {$attrs['contact/email']} Yahoo $yid");

                if ($eid && $yid && $eid != $yid) {
                    # This is a duplicate user.  Merge them.
                    $u = User::get($this->dbhr, $this->dbhm);
                    $u->merge($eid, $yid, "Yahoo Login - YahooId $yahooid = $yid, Email {$attrs['contact/email']} = $eid");
                    #error_log("Yahoo login found duplicate user, merge $yid into $eid");
                }

                $id = $eid ? $eid : $yid;

                if (!$id) {
                    # We don't know them.  Create a user.
                    #
                    # There's a timing window here, where if we had two first-time logins for the same user,
                    # one would fail.  Bigger fish to fry.
                    #
                    # We don't have the firstname/lastname split, only a single name.  Way two go.
                    $id = $u->create(NULL, NULL, Utils::presdef('namePerson', $attrs, NULL), "Yahoo login from $yahooid");

                    if ($id) {
                        # Make sure that we have the Yahoo email recorded as one of the emails for this user.
                        $u = User::get($this->dbhr, $this->dbhm, $id);

                        if (Utils::pres('contact/email', $attrs)) {
                            $u->addEmail($attrs['contact/email'], 0, FALSE);
                        }

                        # Now Set up a login entry.
                        $rc = $this->dbhm->preExec(
                            "INSERT INTO users_logins (userid, type, uid) VALUES (?,'Yahoo',?);",
                            [
                                $id,
                                $yahooid
                            ]
                        );

                        $id = $rc ? $id : NULL;
                    }
                }

                $u = User::get($this->dbhr, $this->dbhm, $id);

                # We have publish permissions for users who login via our platform.
                $u->setPrivate('publishconsent', 1);

                # Make sure we record the most active yahooid for this user, rather than one we happened to pick
                # up on a group sync.
                $u->setPrivate('yahooid', $yahooid);

                $this->dbhm->preExec("UPDATE users_logins SET lastaccess = NOW() WHERE userid = ? AND type = 'Yahoo';",
                    [
                        $id
                    ]);

                if (!$u->getPrivate('fullname') && Utils::pres('namePerson', $attrs)) {
                    # We might have syncd the membership without a good name.
                    $u->setPrivate('fullname', $attrs['namePerson']);
                }

                if ($id) {
                    # We are logged in.
                    $s = new Session($this->dbhr, $this->dbhm);
                    $s->create($id);

                    # Anyone who has logged in to our site has given RIPA consent.
                    $this->dbhm->preExec("UPDATE users SET ripaconsent = 1 WHERE id = ?;",
                        [
                            $id
                        ]);
                    User::clearCache($id);

                    $l = new Log($this->dbhr, $this->dbhm);
                    $l->log([
                        'type' => Log::TYPE_USER,
                        'subtype' => Log::SUBTYPE_LOGIN,
                        'byuser' => $id,
                        'text' => 'Using Yahoo'
                    ]);

                    return([ $s, [ 'ret' => 0, 'status' => 'Success']]);
                }
            } else if (!$this->openid->mode) {
                # We're not logged in.  Redirect to Yahoo to authorise.
                $this->openid->identity = 'https://me.yahoo.com';
//                $this->openid->identity = 'https://api.login.yahoo.com/';
                $this->openid->required = array('contact/email', 'namePerson', 'namePerson/first', 'namePerson/last');
                $this->openid->redirect_uri = $returnto;
                error_log("Get redirect");
                $url = "https://api.login.yahoo.com/oauth2/request_auth?client_id=dj0yJmk9TVo4T1dYYXc5WnJSJmQ9WVdrOVdYTkVWVFF5TjJjbWNHbzlNVFF3T1RBeE1UUTJNZy0tJnM9Y29uc3VtZXJzZWNyZXQmeD1hNw--&redirect_uri=" . urlencode($returnto) . "&response_type=code&language=en-us&scope=openid%20email%20profile";
//                $url = $this->openid->authUrl() . "&key=Iznik";
                error_log("Redirect to $url");
                return [NULL, ['ret' => 1, 'redirect' => $url]];
            }
        }
        catch (\Exception $e)
        {
            error_log("Yahoo Login exception " . $e->getMessage());
        }

        return ([NULL, [ 'ret' => 2, 'status' => 'Login failed']]);
    }
}