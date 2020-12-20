<?php
namespace Freegle\Iznik;

if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');


$id = intval(Utils::presdef('id', $_REQUEST, NULL));
$token = Utils::presdef('token', $_REQUEST, NULL);

$fb = new \Facebook\Facebook([
    'app_id' => FBGRAFFITIAPP_ID,
    'app_secret' => FBGRAFFITIAPP_SECRET
]);

if ($id && $token) {
    # We have to ensure that we are an admin for the page we've chosen, so check the list again.
    try {
        $accessToken = $_SESSION['fbaccesstoken'];
        #error_log("Got token from session $accessToken");

        $totalPages = array();

        $url = '/me/accounts';

        do {
            $getPages = $fb->get($url, $accessToken);
            $body = $getPages->getDecodedBody();
            $pages = Utils::presdef('data', $body, []);
            #error_log("Body " . json_encode($body));

            foreach ($pages as $page) {
                #error_log("Page {$page['name']}");
                $totalPages[] = $page;
            }

            $url = Utils::pres('paging', $body) ? ('/me/accounts?after=' . Utils::presdef('after', $body['paging']['cursors'], NULL)) : NULL;
            #error_log("Next url $url");
        } while ($url);

        $found = FALSE;

        foreach ($totalPages as $page) {
            #echo("Compare {$page['id']} vs $id");
            if (strcmp($page['id'], $id) === 0) {
                $f = new GroupFacebook($dbhr, $dbhm);
                $gid = Utils::presdef('graffitigroup', $_SESSION, NULL);

                if ($gid) {
                    echo "Found group.  You can close this tab now.";
                    $f = new GroupFacebook($dbhr, $dbhm, $gid);
                    $f->add($gid, $page['access_token'], $page['name'], $page['id'], GroupFacebook::TYPE_PAGE);
                    $found = TRUE;
                }
            }
        }

        if (!$found) {
            echo "Hmmm...couldn't find that page in your list.";
        }
    } catch (\Exception $e) {
        echo "Something went wrong " . $e->getMessage();
    }
}
