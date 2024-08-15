<?php
namespace Freegle\Iznik;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

require_once("/etc/iznik.conf");

use Pheanstalk\Pheanstalk;
use JanuSoftware\Facebook\FacebookSession;
use JanuSoftware\Facebook\FacebookJavaScriptLoginHelper;
use JanuSoftware\Facebook\FacebookCanvasLoginHelper;
use JanuSoftware\Facebook\FacebookRequest;
use JanuSoftware\Facebook\FacebookRequestException;

class Facebook
{
    /** @var LoggedPDO $dbhr */
    /** @var LoggedPDO $dbhm */
    private $dbhr;
    private $dbhm;
    private $pheanstalk = NULL;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        return ($this);
    }

    public function getFB($graffiti = FALSE) {
        $appid = $graffiti ? FBGRAFFITIAPP_ID : FBAPP_ID;
        $secret = $graffiti ? FBGRAFFITIAPP_SECRET : FBAPP_SECRET;

        $fb = new \JanuSoftware\Facebook\Facebook([
            'app_id' => $appid,
            'app_secret' => $secret,
            'default_graph_version' =>  'v13.0'
        ]);

        return($fb);
    }

    function login($accessToken = NULL, $code = NULL, $redirectURI = NULL)
    {
        $uid = NULL;
        $ret = [
            'ret' => 2,
            'status' => 'Login failed'
        ];

        $s = NULL;

        $fb = $this->getFB();

        try {
            if (!$accessToken) {
                # If we weren't passed an access token, get one, we might have been passed a code which we can
                # exchange for one, or we might get one from the JS SDK.
                $helper = $fb->getJavaScriptHelper();
                $accessToken = $code ? $fb->getOAuth2Client()->getAccessTokenFromCode($code, $redirectURI) : $helper->getAccessToken();
            } else {
                $accessToken = new \JanuSoftware\Facebook\Authentication\AccessToken($accessToken);
            }

            if ($accessToken) {
                list($s, $ret) = $this->processAccessTokenLogin($fb, $accessToken);
            }
        } catch (\Exception $e) {
            $ret = [
                'ret' => 2,
                'status' => "Didn't manage to get a Facebook session: " . $e->getMessage()
            ];

            #error_log("Failed " . var_export($ret, TRUE));
        }

        return ([$s, $ret]);
    }

    public function getLongLivedToken($fb, $accessToken, $graffiti = FALSE) {
        $appid = $graffiti ? FBGRAFFITIAPP_ID : FBAPP_ID;

        // The OAuth 2.0 client handler helps us manage access tokens
        $oAuth2Client = $fb->getOAuth2Client();

        // Get the access token metadata from /debug_token
        $tokenMetadata = $oAuth2Client->debugToken($accessToken);
        #error_log("Token metadata " . var_export($tokenMetadata, TRUE));

        // Validation (these will throw SDKException's when they fail)
        $tokenMetadata->validateAppId($appid);
        $tokenMetadata->validateExpiration();

        $oAuth2Client = $fb->getOAuth2Client();

        // Get the access token metadata from /debug_token
        $tokenMetadata = $oAuth2Client->debugToken($accessToken);
        #error_log("Token metadata " . var_export($tokenMetadata, TRUE));

        // Validation (these will throw SDKException's when they fail)
        $tokenMetadata->validateAppId($appid);
        $tokenMetadata->validateExpiration();

        // Exchanges a short-lived access token for a long-lived one
        try {
            $accessToken = $oAuth2Client->getLongLivedAccessToken($accessToken);
        } catch (\JanuSoftware\Facebook\Exception\SDKException $e) {
            # No need to fail the login = proceed with our short one.
            error_log("Error getting long-lived access token: " . $e->getMessage());
        }

        return $accessToken;
    }

    private function processAccessTokenLogin($fb, $accessToken) {
        $s = NULL;

        if (!$accessToken->isLongLived()) {
            // Exchanges a short-lived access token for a long-lived one
            try {
                $accessToken = $this->getLongLivedToken($fb, $accessToken);
            } catch (\JanuSoftware\Facebook\Exception\SDKException $e) {
                # No need to fail the login = proceed with our short one.
                error_log("Error getting long-lived access token: " . $e->getMessage());
            }

            #error_log("Got long lived access token " . var_export($accessToken->getValue(), TRUE));
        }

        try {
            # We think we have a session.  See if we can get our data.
            #
            # Note that we may not get an email, and nowadays the id we are given is a per-app id not
            # something that can be used to identify the user.
            $response = $fb->get('/me?fields=id,name,first_name,last_name,email', $accessToken);
            $fbme = $response->getDecodedBody();

            $s = $this->facebookMatchOrCreate($fbme, $accessToken);

            if ($s) {
                $ret = 0;
                $status = 'Success';
            }
        } catch (\Exception $e) {
            $ret = 1;
            $status = "Failed to get user details " . $e->getMessage();
        }

        return([$s, [
            'ret' => $ret,
            'status' => $status,
            'accesstoken' => $accessToken
        ]]);
    }

    public function loadCanvas() {
        # We think we're being called from within a Facebook canvas, i.e. the Facebook app.
        $s = NULL;
        $ret = [
            'ret' => 2,
            'status' => 'Login failed'
        ];

        if (session_status() == PHP_SESSION_NONE) {
            @session_start();
        }

        if (!isset($_SESSION) || !Utils::pres('id', $_SESSION)) {
            # We're not already logged in.  Try to get an access token.
            $fb = $this->getFB();

            $accessToken = NULL;

            # Try to get our session set up.  If we don't, then we'll just proceed as logged out.
            try {
                $helper = $fb->getCanvasHelper();
                $accessToken = $helper->getAccessToken();
                list ($s, $ret) = $this->processAccessTokenLogin($fb, $accessToken);
            } catch (\Exception $e) {
                $ret = [
                    'ret' => 2,
                    'status' => "Didn't manage to get a Facebook session: " . $e->getMessage()
                ];
            }
        }

        return([$s, $ret]);
    }

    public function fbpost($fbid, $notif) {
        $fb = new \JanuSoftware\Facebook\Facebook([
                                        'app_id' => FBAPP_ID,
                                        'app_secret' => FBAPP_SECRET,
                                         'default_graph_version' =>  'v13.0'
                                    ]);

        $fb->setDefaultAccessToken(FBAPP_ID . '|' . FBAPP_SECRET);

        return $fb->post("/$fbid/notifications", $notif);
    }

    public function executeNotify($fbid, $message, $href) {
        $result = NULL;

        try {
            $notif = [
                'template' => $message,
                'href' => $href
            ];

            $result = $this->fbpost($fbid, $notif);
            error_log("...notified Facebook $fbid OK");
            #error_log("Notify returned " . var_export($result, TRUE));
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            error_log("FB notify failed with " . $msg);

            if (strpos($msg, '(#803) Some of the aliases you requested do not exist:') !== FALSE ||
                strpos($msg, '(#200) Cannot send notifications to a user who has not installed the app') !== FALSE) {
                # Our Facebook info is no longer valid.
                error_log("...remove");
                $this->dbhm->preExec("DELETE FROM users_logins WHERE uid = ? AND type = ?;", [
                    $fbid,
                    User::LOGIN_FACEBOOK
                ]);
            }
        }

        return $result;
    }

    public function uthook($rc = NULL) {
        # Mocked in UT to force an exception.
        return($rc);
    }

    public function pheanPut($str) {
        return $this->pheanstalk->put($str);
    }

    public function getProfilePicture($uid) {
        $fb = $this->getFB();
        $ret = NULL;

        try {
            $res = $fb->get("$uid/picture", FBAPP_ID . "|" . FBAPP_SECRET);
            $url = Utils::presdef('Location', $res->getHeaders(), NULL);

            if ($url) {
                $ret = [
                    'url' => $url,
                    'turl' => $url,
                    'default' => FALSE,
                    'facebook' => TRUE
                ];
            }
        } catch (\Throwable $e) {
            error_log("Profile get failed with " . $e->getMessage());
        }

        return $ret;
    }

    public function loginLimited($jwt) {
        // Facebook limited login returns a JWT.  We need to fetch the public keys, and then decode it.
        $s = NULL;
        $ret = [
            'ret' => 1,
            'status' => 'Login with limited token failed'
        ];

        try {
            $ctx = stream_context_create(array('http'=> [
                'timeout' => 1,
                "method" => "GET",
            ]));

            $keys = file_get_contents("https://limited.facebook.com/.well-known/oauth/openid/jwks/", FALSE, $ctx);

            if ($keys) {
                $keys = json_decode($keys, TRUE);

                if ($keys) {
                    JWT::$leeway = 60;
                    $fbme = JWT::decode($jwt, JWK::parseKeySet($keys));

                    $s = $this->facebookMatchOrCreate($fbme, $jwt);

                    if ($s) {
                        $ret = 0;
                        $status = 'Success';
                    }

                }
            }
        } catch (\Exception $e) {
            error_log("JWT validation failed with " . $e->getMessage());
            $ret = [
                'ret' => 2,
                'status' => "JWT validation failed with " . $e->getMessage()
            ];
        }

        return [ $s, $ret ];
    }

    private function facebookMatchOrCreate($fbme, $accessToken) {
        $s = NULL;

        $fbemail = Utils::presdef('email', $fbme, null);
        $fbuid = Utils::presdef('id', $fbme, null);
        $firstname = Utils::presdef('first_name', $fbme, null);
        $lastname = Utils::presdef('last_name', $fbme, null);
        $fullname = Utils::presdef('name', $fbme, null);

        # See if we know this user already.  We might have an entry for them by email, or by Facebook ID.
        $u = User::get($this->dbhr, $this->dbhm);
        $eid = $fbemail ? $u->findByEmail($fbemail) : null;
        $fid = $fbuid ? $u->findByLogin('Facebook', $fbuid) : null;
        #error_log("Email $eid  from $fbemail Facebook $fid, f $firstname, l $lastname, full $fullname");

        if ($eid && $fid && $eid != $fid) {
            # This is a duplicate user.  Merge them.
            $u = User::get($this->dbhr, $this->dbhm);
            $u->merge($eid, $fid, "Facebook Login - FacebookID $fid, Email $fbemail = $eid");
        }

        $id = $eid ? $eid : $fid;
        #error_log("Login id $id from $eid and $fid");

        if (!$id) {
            # We don't know them.  Create a user.
            #
            # There's a timing window here, where if we had two first-time logins for the same user,
            # one would fail.  Bigger fish to fry.
            #
            # We don't have the firstname/lastname split, only a single name.  Way two go.
            $id = $u->create($firstname, $lastname, $fullname, "Facebook login from $fid");

            if ($id) {
                # Make sure that we have the Yahoo email recorded as one of the emails for this user.
                $u = User::get($this->dbhr, $this->dbhm, $id);

                if ($fbemail) {
                    $u->addEmail($fbemail, 0, false);
                }

                # Now Set up a login entry.  Use IGNORE as there is a timing window here.
                $rc = $this->dbhm->preExec(
                    "INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?,'Facebook',?);",
                    [
                        $id,
                        $fbuid
                    ]
                );

                $id = $rc ? $id : null;
            }
        } else {
            # We know them - but we might not have all the details.
            $u = User::get($this->dbhr, $this->dbhm, $id);

            if (!$eid) {
                $u->addEmail($fbemail, 0, false);
            }

            if (!$fid) {
                $this->dbhm->preExec(
                    "INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?,'Facebook',?);",
                    [
                        $id,
                        $fbuid
                    ]
                );
            }
        }

        # Save off the access token, which we might need, and update the access time.
        $this->dbhm->preExec(
            "UPDATE users_logins SET lastaccess = NOW(), credentials = ? WHERE userid = ? AND type = 'Facebook';",
            [
                (string)$accessToken,
                $id
            ]
        );

        # We might have have them without a good name.
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
                        'text' => "Using Facebook $fid"
                    ]);

            $ret = 0;
            $status = 'Success';
        }

        return [$s, $ret, $status];
    }
}