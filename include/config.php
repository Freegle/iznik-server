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

    # Autoload for our code.
    spl_autoload_register(function ($class_name) {
        $p = strrpos($class_name, '\\');
        $class = $class_name;

        if ($p !== FALSE) {
            $q = strpos($class_name, 'Freegle\Iznik');

            if ($q === 0) {
                $class = substr($class_name, $p + 1);
            }
        }

        foreach ([ '/include/', '/include/user/', '/include/session/', '/include/group/', '/include/message/', '/include/misc/', '/include/chat/', '/include/newsfeed/', '/include/spam/', '/include/config/', '/include/dashboard/', '/include/mail/', '/include/noticeboard/', '/test/ut/', '/include/booktastic/' ] as $dir) {
            $fn = IZNIK_BASE . $dir . $class . '.php';
            #error_log("Check $class_name $fn");
            if (file_exists($fn)) {
                #error_log("Exists");
                require_once $fn;
                break;
            }
        }
    });

    define('DUPLICATE_POST_PROTECTION', 10); # Set to 0 to disable
    define('API_RETRIES', 5);
    define('REDIS_TTL', 30);

    define('BROWSERTRACKING', TRUE);
    define('INCLUDE_TEMPLATE_NAME', TRUE);

    # SQL caching is disabled at the moment.  Caching operations in redis is fairly expensive, and we've had
    # more benefit in reducing the number of ops in the first place.  Not quite ready to remove the code yet.
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

    if (!defined('SQLLOG')) {
        define('SQLLOG', FALSE);
    }

    # There are some historical domains.
    define('OURDOMAINS', USER_DOMAIN . ",direct.ilovefreegle.org,republisher.freegle.in");
}

if (!defined('RETURN_PATH')) {
    # Using Return Path function.
    define('RETURN_PATH', TRUE);
}

if (!defined('MMDB')) {
    if (file_exists('/usr/share/GeoIP/GeoLite2-Country.mmdb')) {
        define('MMDB', '/usr/share/GeoIP/GeoLite2-Country.mmdb');
    } else if (file_exists('/usr/local/share/GeoIP/GeoLite2-Country.mmdb')) {
        define('MMDB', '/usr/local/share/GeoIP/GeoLite2-Country.mmdb');
    } else {
        define('MMDB', '/var/lib/GeoIP/GeoLite2-Country.mmdb');
    }
}
