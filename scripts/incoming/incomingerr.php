<?php

mail("geeks@ilovefreegle.org", "Iznik: Problem with incoming.php, will retry ID " . $argv[2], "This means incoming mails are not reaching the DB, but should be queued in exim.\n\n" . var_export($argv, TRUE) . "\n\n" . var_export($_ENV, TRUE), [], "-fsupport@modtools.org");

?>

