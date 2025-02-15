<?php
namespace Freegle\Iznik;

if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$groupid = intval(Utils::presint('groupid', $_REQUEST, 0));

$fb = new \JanuSoftware\Facebook\Facebook([
    'app_id' => FBGRAFFITIAPP_ID,
    'app_secret' => FBGRAFFITIAPP_SECRET,
    'default_graph_version' =>  'v13.0'
]);

$helper = $fb->getRedirectLoginHelper();

if (isset($_GET['state'])) {
    $helper->getPersistentDataHandler()->set('state', $_GET['state']);
}

try {
    $accessToken = $helper->getAccessToken();
    $_SESSION['fbaccesstoken'] = (string)$accessToken;
    #echo "Access token {$_SESSION['fbaccesstoken']}";

    $ret = $fb->get('/me', $accessToken);
    $me = $ret->getDecodedBody();

    $totalPages = [];
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

    usort($totalPages, function ($a, $b) {
        return (strcmp($a['name'], $b['name']));
    });
    ?>
  <p>
    Facebook may have broken something.  If you don't see any groups here, tou can't relink at the moment.  See
    <a href="https://discourse.ilovefreegle.org/t/fb-page-linking-not-working">this Discourse thread</a> for details.
  </p>
<!--    <p>These are the Facebook pages you manage.  Click on the one you want to link to your group.</p>-->
    <?php
    foreach ($totalPages as $page) {
        echo '<a href="/facebook/facebook_settoken.php?id=' . urlencode($page['id']) . '&groupid=' . $groupid . '&token=' . urlencode($page['access_token']) . '">' . $page['name'] . '</a><br />';
    }
} catch(\JanuSoftware\Facebook\Exception\FacebookResponseException $e) {
    // When Graph returns an error
    echo 'Graph returned an error: ' . $e->getMessage();
    exit;
} catch(\JanuSoftware\Facebook\Exception\SDKException $e) {
    // When validation fails or other local issues
    echo 'Facebook SDK returned an error: ' . $e->getMessage();
    exit;
}

if (! isset($accessToken)) {
    if ($helper->getError()) {
        header('HTTP/1.0 401 Unauthorized');
        echo "Error: " . $helper->getError() . "\n";
        echo "Error Code: " . $helper->getErrorCode() . "\n";
        echo "Error Reason: " . $helper->getErrorReason() . "\n";
        echo "Error Description: " . $helper->getErrorDescription() . "\n";
    } else {
        header('HTTP/1.0 400 Bad Request');
        echo 'Bad request';
    }
    exit;
}
