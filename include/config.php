<?php

if (!defined('REDIS_CONNECT')) {
    if (file_exists('/var/run/redis/redis.sock')) {
        define('REDIS_CONNECT', '/var/run/redis/redis.sock');
    } else {
        define('REDIS_CONNECT', '127.0.0.1');
    }
}

if (!defined('IZNIK_BASE')) {
    define('IZNIK_BASE', dirname(__FILE__) . '/..');
    require_once(IZNIK_BASE . '/composer/vendor/autoload.php');

    define('DUPLICATE_POST_PROTECTION', 10); # Set to 0 to disable
    define('API_RETRIES', 5);
    define('REDIS_TTL', 30);

    define('BROWSERTRACKING', TRUE);
    define('INCLUDE_TEMPLATE_NAME', TRUE);
    define('SQLLOG', FALSE);
    define('SQLCACHE', FALSE);
    define('EVENTLOG', TRUE);
    define('TWIG_CACHE', '/tmp/twig_cache');

    if (!defined('XHPROF')) {
        define('XHPROF', FALSE);
    }

    define('COOKIE_NAME', 'session');

    # Our servers run on UTC
    date_default_timezone_set('UTC');

    # Per-machine config or overrides
    require_once('/etc/iznik.conf');

    # There are some historical domains.
    define('OURDOMAINS', USER_DOMAIN . ",direct.ilovefreegle.org,republisher.freegle.in");

    if (!defined('MODTOOLS')) {
        # Err on the safe side so that cron scripts etc return all data.
        define('MODTOOLS', TRUE);
    }
}

if (!defined('RETURN_PATH')) {
    # Using Return Path function.
    define('RETURN_PATH', TRUE);
}

