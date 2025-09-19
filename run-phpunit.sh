#!/bin/bash
# Wrapper script to run PHPUnit tests with chat_notify stopped

echo "Stopping chat_notify background job..."
# Create abort file to signal background jobs to stop
touch /tmp/iznik.mail.abort

# Kill any existing chat_notify processes
pkill -f chat_notifyemail_user2user.php 2>/dev/null

echo "Running PHPUnit tests with coverage..."

# Handle path arguments - if they start with /var/www/iznik/, make them relative
TEST_PATH="$@"
if [[ "$TEST_PATH" == /var/www/iznik/* ]]; then
    # Remove the /var/www/iznik/ prefix to make it relative
    TEST_PATH="${TEST_PATH#/var/www/iznik/}"
    echo "Adjusted test path to: $TEST_PATH"
fi

# Pass all arguments to the PHPUnit test runner with coverage generation
# Enable xdebug coverage mode for this run
cd /var/www/iznik
XDEBUG_MODE=coverage php /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit --configuration /var/www/iznik/test/ut/php/phpunit.xml --coverage-clover /tmp/phpunit-coverage.xml $TEST_PATH

# Store the exit code
TEST_EXIT_CODE=$?

# Check if coverage file was created and persist it
if [ $TEST_EXIT_CODE -eq 0 ]; then
    if [ ! -f /tmp/phpunit-coverage.xml ]; then
        echo "ERROR: PHPUnit tests passed but coverage file was not generated at /tmp/phpunit-coverage.xml"
        TEST_EXIT_CODE=1
    else
        echo "Coverage file generated successfully at /tmp/phpunit-coverage.xml"
        # Show file size for debugging
        ls -la /tmp/phpunit-coverage.xml
        # Also copy to output log location to ensure it persists
        cp /tmp/phpunit-coverage.xml /tmp/phpunit-coverage.xml.backup 2>/dev/null || true
    fi
else
    echo "Tests failed with exit code $TEST_EXIT_CODE, skipping coverage check"
fi

echo "Cleaning up and restarting chat_notify..."
# Remove abort file
rm -f /tmp/iznik.mail.abort

# Restart the chat_notify job in background
cd /var/www/iznik/scripts/cron && nohup php ./chat_notifyemail_user2user.php >> /tmp/iznik.chat_notifyemail_user2user.out 2>&1 &

echo "Tests completed with exit code: $TEST_EXIT_CODE"
exit $TEST_EXIT_CODE