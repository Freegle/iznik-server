#!/bin/bash
# Wrapper script to run PHPUnit tests with chat_notify stopped

echo "Stopping chat_notify background job..."
# Create abort file to signal background jobs to stop
touch /tmp/iznik.mail.abort

# Kill any existing chat_notify processes
pkill -f chat_notifyemail_user2user.php 2>/dev/null

echo "Running PHPUnit tests with coverage..."
# Pass all arguments to the PHPUnit test runner with coverage generation
php /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit --configuration /var/www/iznik/test/ut/php/phpunit.xml --coverage-clover /tmp/phpunit-coverage.xml "$@"

# Store the exit code
TEST_EXIT_CODE=$?

# Check if coverage file was created
if [ $TEST_EXIT_CODE -eq 0 ]; then
    if [ ! -f /tmp/phpunit-coverage.xml ]; then
        echo "ERROR: PHPUnit tests passed but coverage file was not generated at /tmp/phpunit-coverage.xml"
        TEST_EXIT_CODE=1
    else
        echo "Coverage file generated successfully at /tmp/phpunit-coverage.xml"
    fi
fi

echo "Cleaning up and restarting chat_notify..."
# Remove abort file
rm -f /tmp/iznik.mail.abort

# Restart the chat_notify job in background
cd /var/www/iznik/scripts/cron && nohup php ./chat_notifyemail_user2user.php >> /tmp/iznik.chat_notifyemail_user2user.out 2>&1 &

echo "Tests completed with exit code: $TEST_EXIT_CODE"
exit $TEST_EXIT_CODE