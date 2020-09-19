<?php
namespace Freegle\Iznik;

require_once("/etc/iznik.conf");

use AppleSignIn\ASDecoder;
use AppleSignIn\Vendor\JWT;

class Apple
{
    /** @var LoggedPDO $dbhr */
    /** @var LoggedPDO $dbhm */
    private $dbhr;
    private $dbhm;
    private $access_token;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        return ($this);
    }

    public function getPayload($token) {
        # So we can mock.
        return ASDecoder::getAppleSignInPayload($token);
    }

    function login($credentials)
    {
        $uid = NULL;
        $ret = 2;
        $status = 'Login failed';
        $s = NULL;

        #error_log("Credentials " . var_export($credentials, TRUE));

        $token = Utils::presdef('identityToken', $credentials, NULL);
        $fullName = Utils::presdef('fullName', $credentials, NULL);
        $firstname = Utils::presdef('givenName', $fullName, NULL);
        $lastname = Utils::presdef('familyName', $fullName, NULL);

        if ($token) {
            try {
                JWT::$leeway = 1000000;
                $appleSignInPayload = $this->getPayload($token);

                $email = $appleSignInPayload->getEmail();
                $user = $appleSignInPayload->getUser();
                $isValid = $appleSignInPayload->verifyUser($user);

                if ($isValid) {
                    # See if we know this user already.  We might have an entry for them by email, or by Facebook ID.
                    $u = User::get($this->dbhr, $this->dbhm);
                    $eid = $u->findByEmail($email);
                    $aid = $u->findByLogin('Apple', $user);
                }

                if ($eid && $aid && $eid != $aid) {
                    # This is a duplicate user.  Merge them.
                    $u = User::get($this->dbhr, $this->dbhm);
                    $u->merge($eid, $aid, "Apple Login - Apple ID $aid, Email $email = $eid");
                }

                $id = $eid ? $eid : $aid;
                #error_log("Login id $id from $eid and $gid");

                if (!$id) {
                    # We don't know them.  Create a user.
                    #
                    # There's a timing window here, where if we had two first-time logins for the same user,
                    # one would fail.  Bigger fish to fry.
                    #
                    # firstname and lastname might be null, but if they are then we will invent a name later in
                    # User::getName.
                    $id = $u->create($firstname, $lastname, NULL, "Apple login from $aid");

                    if ($id) {
                        # Make sure that we have the email recorded as one of the emails for this user.
                        $u = User::get($this->dbhr, $this->dbhm, $id);

                        if ($email) {
                            $u->addEmail($email, 0, FALSE);
                        }

                        # Now Set up a login entry.  Use IGNORE as there is a timing window here.
                        $rc = $this->dbhm->preExec(
                            "INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?,'Apple',?);",
                            [
                                $id,
                                $user
                            ]
                        );

                        $id = $rc ? $id : NULL;
                    }
                } else {
                    # We know them - but we might not have all the details.
                    $u = User::get($this->dbhr, $this->dbhm, $id);

                    if (!$eid) {
                        $u->addEmail($email, 0, FALSE);
                    }

                    if (!$aid) {
                        $this->dbhm->preExec(
                            "INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?,'Apple',?);",
                            [
                                $id,
                                $user
                            ]
                        );
                    }
                }

                # We have publish permissions for users who login via our platform.
                $u->setPrivate('publishconsent', 1);

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
                        'text' => "Using Apple $user"
                    ]);

                    $ret = 0;
                    $status = 'Success';
                }
            } catch (\Exception $e) {
                $ret = 2;
                $status = "Didn't manage to validate Apple session: " . $e->getMessage();
                error_log("Didn't manage to validate Apple session " . $e->getMessage());
            }
        }

        return ([$s, [ 'ret' => $ret, 'status' => $status]]);
    }
}