<?php
# This file should be suitably modified, then go into /etc/iznik.conf
define('SQLHOST', '127.0.0.1');
define('SQLHOSTS_READ', '127.0.0.1:3307,127.0.0.1:3306');
define('SQLHOSTS_MOD', '127.0.0.1:3307,127.0.0.1:3306');
define('SQLDB', 'iznik');
define('SQLUSER', 'root');
define('SQLPASSWORD', '');
define('PASSWORD_SALT', 'zzzz');
define('MODERATOR_EMAIL', 'modtools@modtools.org');
define('MODBOT_USER', 'modbot@modtools.org');

# We use Postgresql for limited purposes.
define('PGSQLHOST', '127.0.0.1:5432');
define('PGSQLUSER', 'iznik');
define('PGSQLDB', 'iznik');
define('PGSQLPASSWORD', 'iznik');

# Logos
define('USERLOGO', 'https://www.ilovefreegle.org/icon.png');
define('MODLOGO', 'https://modtools.org/images/modlogo-large.jpg');

# We can query Trash Nothing to get real email addresses for their users.
define('TNKEY', 'zzzzz');

# TN uses this to authenticate emails so that we don't need to check them for spam.
define('TNSECRET', 'zzzz');

# We push posts to Freebie Alerts
define('FREEBIE_ALERTS_KEY', NULL);

# We can use push notifications
define('GOOGLE_PROJECT', 'zzz');
define('GOOGLE_PUSH_KEY', 'zzzz');
define('GOOGLE_GEMINI_API_KEY', 'zzzz');

# Other Google keys
define('GOOGLE_VISION_KEY', 'zzz');
define('GOOGLE_CLIENT_ID', 'zzz');
define('GOOGLE_CLIENT_SECRET', 'zzz');
define('GOOGLE_APP_NAME', 'zzz');
define('GOOGLE_SITE_VERIFICATION', 'zzz');
define('GOOGLE_PERSPECTIVE_KEY', 'zzz');

# Yahoo App keys
define('YAHOO_APPID', 'zzz');
define('YAHOO_CLIENT_ID', 'zzz');
define('YAHOO_CLIENT_SECRET', 'zzz');

# For website analysis.
define('INSPECTLET', 'zzz');

# We support Facebook login, but you have to create your own app
define('FBAPP_ID', 'zzz');
define('FBAPP_SECRET', 'zzz');
define('FBAPP_CLIENT_TOKEN', 'zzz');

# We have a separate app for posting to group pages, in case this one gets blocked
define('FBGRAFFITIAPP_ID', 'zzz');
define('FBGRAFFITIAPP_SECRET', 'zzz');

# We can send SMS
define('TWILIO_SID', 'zzzz');
define('TWILIO_AUTH', 'zzzz');
define('TWILIO_NUMBER', 'zzzz');
define('TWILIO_FROM', 'zzz');
define('TWILIO_TEST_SID', 'AC7c44dd2723b38c4525befe274be0a104');
define('TWILIO_TEST_AUTHTOKEN', '567b8afd6eeb940cf83e67c749f2cd21');
define('TWILIO_TEST_FROM', '+15005550006');
define('TWILIO_TEST_FROM_INVALID', '+15005550001');

# We access PayPal to retrieve info on donations
define('PAYPAL_USERNAME', 'zzzz');
define('PAYPAL_PASSWORD', 'zzzz');
define('PAYPAL_SIGNATURE', 'zzzz');
define('PAYPAL_THANKS_FROM', 'info@ilovefreegle.org');

# We use Mapbox to get isochrones for users.
define('MAPBOX_TOKEN', 'zzzz');

# Discourse SSO
define('DISCOURSE_SECRET', 'zzz');
define('DISCOURSE_APIKEY', 'zzz');
define('DISCOURSE_API', 'zzz');

# Forum SSO
define('FORUM_SECRET', 'zzz');
define('FORUM_APIKEY', 'zzz');
define('FORUM_API', 'zzz');

# We fetch jobs to display.
define('WHATJOBS_DUMP', 'zzzz');
define('WHATJOBS_DUMP2', 'zzzz');

define('SERVER_LIST', '');
define('GEOCODER', 'geocode.ilovefreegle.org');

# We use beanstalk for backgrounding.
define('PHEANSTALK_SERVER', '127.0.0.1');

# Host to monitor
define('MONIT_HOST', 'zzz');

# We use Sentry to capture exceptions.  But we don't define it here, as UT doesn't use it.
# define('SENTRY_DSN', 'zzzz');

# You can force all user activity onto a test group
define('USER_GROUP_OVERRIDE', 'FreeglePlayground');

# The domain for users to access.
define('USER_SITE', 'www.ilovefreegle.org');

# The domain for mods to access.
define('MOD_SITE', 'modtools.org');

$host = $_SERVER && array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : 'iznik.modtools.org';
define('SITE_HOST', $host);
define('CHAT_HOST', 'users.ilovefreegle.org');
define('CHAT_PORT', 555);

switch($host) {
    case 'iznik.modtools.org':
        define('SITE_NAME', 'Iznik');
        define('SITE_DESC', 'Making moderating easier');
        define('FAVICON_HOME', 'modtools');
        define('MANIFEST_STARTURL', 'modtools');
        define('COOKIE_DOMAIN', 'modtools.org');
        break;
    case 'dev.modtools.org':
    case 'modtools.org':
        define('SITE_NAME', 'Iznik');
        define('SITE_DESC', 'Making moderating easier');
        define('FAVICON_HOME', 'modtools');
        define('MANIFEST_STARTURL', 'modtools');
        define('COOKIE_DOMAIN', 'modtools.org');
        break;
    case 'iznik.ilovefreegle.org':
        define('SITE_NAME', 'Freegle');
        define('SITE_DESC', 'Online dating for stuff');
        define('FAVICON_HOME', 'user');
        define('MANIFEST_STARTURL', '');
        define('COOKIE_DOMAIN', 'ilovefreegle.org');
        break;
}


# We archive to our own hosts, which are fronted by round-robin DNS.
define('IMAGE_DOMAIN', 'dev.modtools.org');
define('IMAGE_ARCHIVED_DOMAIN', 'freegle.blob.core.windows.net');
define('CDN_SSH_USER', 'root');
define('CDN_SSH_PUBLIC_KEY', '/home/travis/.ssh/id_rsa.pub');
define('CDN_SSH_PRIVATE_KEY', '/home/travis/.ssh/id_rsa');

# Domain for email addresses for our users
define('USER_DOMAIN', 'users.ilovefreegle.org');

# Email submissions
define('GROUP_DOMAIN', 'groups.ilovefreegle.org');

# Contact emails
define('SUPPORT_ADDR', 'support@ilovefreegle.org');
define('INFO_ADDR', 'info@ilovefreegle.org');
define('LJ_SUPPORT_ADDR', 'support@lovejunk.com');
define('GEEKS_ADDR', 'geeks@ilovefreegle.org');
define('BOARD_ADDR', 'board@ilovefreegle.org');
define('MENTORS_ADDR', 'mentors@ilovefreegle.org');
define('NEWGROUPS_ADDR', 'newgroups@ilovefreegle.org');
define('VOLUNTEERS_ADDR', 'volunteers@ilovefreegle.org');
define('FUNDRAISING_ADDR', 'xxx');
define('CHITCHAT_SUPPORT_ADDR', 'support@ilovefreegle.org');
define('COUNCILS_ADDR', 'councils@ilovefreegle.org');
define('TREASURER_ADDR', 'treasurer@ilovefreegle.org');
define('TN_ADDR', 'zzz');
define('FBL_ADDR', 'fbl@users.ilovefreegle.org');

define('NOREPLY_ADDR', 'noreply@ilovefreegle.org');

# Central mods mailing list, where we send periodic mails
define('CENTRAL_MAIL_TO', 'discoursereplies+Tech@ilovefreegle.org');
define('CENTRAL_MAIL_FROM', 'geeks@ilovefreegle.org');

define('DONATION_TARGET', 2000);
define('DONATIONS_EXCLUDE', 'ppgfukpay@paypalgivingfund.org,paypal.msb@tipalti.com');

# For test scripts
define('USER_TEST_SITE', 'https://iznik.ilovefreegle.org');
define('MOD_TEST_SITE', 'https://iznik.modtools.org');
define('PLAYGROUND_TOKEN', 'zzzz');
define('PLAYGROUND_SECRET', 'zzzz');

define('LOVE_JUNK_API', 'https://staging-elmer.api-lovejunk.com/elmer/v1');
define('LOVE_JUNK_SECRET', 'LOVE_JUNK_SECRET');

define('IMAGE_DELIVERY', NULL);
define('TUS_UPLOADER', "https://tusd.tusdemo.net/files/");

define('REACH_FEED', NULL);
define('REACH_USER', NULL);
define('REACH_PASSWORD', NULL);
define('STRIPE_SECRET_KEY', 'zzz');
define('STRIPE_SECRET_KEY_TEST', 'zzz');